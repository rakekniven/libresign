<?php

declare(strict_types=1);
/**
 * @copyright Copyright (c) 2023 Vitor Mattos <vitor@php.rio>
 *
 * @author Vitor Mattos <vitor@php.rio>
 *
 * @license GNU AGPL version 3 or any later version
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 */

namespace OCA\Libresign\Service;

use InvalidArgumentException;
use mikehaertl\pdftk\Command;
use OC\AppFramework\Http as AppFrameworkHttp;
use OCA\Libresign\AppInfo\Application;
use OCA\Libresign\DataObjects\VisibleElementAssoc;
use OCA\Libresign\Db\AccountFile;
use OCA\Libresign\Db\AccountFileMapper;
use OCA\Libresign\Db\File as FileEntity;
use OCA\Libresign\Db\FileElementMapper;
use OCA\Libresign\Db\FileMapper;
use OCA\Libresign\Db\IdentifyMethod;
use OCA\Libresign\Db\IdentifyMethodMapper;
use OCA\Libresign\Db\SignRequest as SignRequestEntity;
use OCA\Libresign\Db\SignRequestMapper;
use OCA\Libresign\Db\UserElementMapper;
use OCA\Libresign\Events\SignedEvent;
use OCA\Libresign\Exception\EmptyRootCertificateException;
use OCA\Libresign\Exception\LibresignException;
use OCA\Libresign\Handler\PdfTk\Pdf;
use OCA\Libresign\Handler\Pkcs12Handler;
use OCA\Libresign\Handler\Pkcs7Handler;
use OCA\Libresign\Helper\JSActions;
use OCA\Libresign\Helper\ValidateHelper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\Files\Config\IUserMountCache;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use OCP\Http\Client\IClientService;
use OCP\IConfig;
use OCP\IL10N;
use OCP\ITempManager;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;
use Sabre\DAV\UUIDUtil;
use TypeError;

class SignFileService {
	/** @var SignRequestEntity */
	private $signRequest;
	/** @var string */
	private $password;
	/** @var FileEntity */
	private $libreSignFile;
	/** @var VisibleElementAssoc[] */
	private $elements = [];
	/** @var bool */
	private $signWithoutPassword = false;
	private ?Node $fileToSign = null;
	private string $userUniqueIdentifier = '';
	private string $friendlyName = '';

	public function __construct(
		protected IL10N $l10n,
		private FileMapper $fileMapper,
		private SignRequestMapper $signRequestMapper,
		private AccountFileMapper $accountFileMapper,
		private Pkcs7Handler $pkcs7Handler,
		private Pkcs12Handler $pkcs12Handler,
		protected FolderService $folderService,
		private IClientService $client,
		private IUserManager $userManager,
		protected LoggerInterface $logger,
		private IConfig $config,
		protected ValidateHelper $validateHelper,
		private IRootFolder $root,
		private IUserMountCache $userMountCache,
		private FileElementMapper $fileElementMapper,
		private UserElementMapper $userElementMapper,
		private IEventDispatcher $eventDispatcher,
		private IURLGenerator $urlGenerator,
		private SignMethodService $signMethod,
		private IdentifyMethodMapper $identifyMethodMapper,
		private ITempManager $tempManager
	) {
	}

	/**
	 * Can delete sing request
	 */
	public function canDeleteRequestSignature(array $data): void {
		if (!empty($data['uuid'])) {
			$signatures = $this->signRequestMapper->getByFileUuid($data['uuid']);
		} elseif (!empty($data['file']['fileId'])) {
			$signatures = $this->signRequestMapper->getByNodeId($data['file']['fileId']);
		} else {
			throw new \Exception($this->l10n->t('Inform or UUID or a File object'));
		}
		$signed = array_filter($signatures, fn ($s) => $s->getSigned());
		if ($signed) {
			throw new \Exception($this->l10n->t('Document already signed'));
		}
		array_walk($data['users'], function ($user) use ($signatures) {
			$exists = array_filter($signatures, fn ($s) => $s->getEmail() === $user['email']);
			if (!$exists) {
				throw new \Exception($this->l10n->t('No signature was requested to %s', $user['email']));
			}
		});
	}

	/**
	 * @psalm-suppress MixedReturnStatement
	 * @psalm-suppress MixedMethodCall
	 */
	public function notifyCallback(File $file): void {
		$uri = $this->libreSignFile->getCallback();
		if (!$uri) {
			$uri = $this->config->getAppValue(Application::APP_ID, 'webhook_sign_url');
			if (!$uri) {
				return;
			}
		}
		$options = [
			'multipart' => [
				[
					'name' => 'uuid',
					'contents' => $this->libreSignFile->getUuid(),
				],
				[
					'name' => 'status',
					'contents' => $this->libreSignFile->getStatus(),
				],
				[
					'name' => 'file',
					'contents' => $file->fopen('r'),
					'filename' => $file->getName()
				]
			]
		];
		$this->client->newClient()->post($uri, $options);
	}

	/**
	 * @return static
	 */
	public function setLibreSignFile(FileEntity $libreSignFile): self {
		$this->libreSignFile = $libreSignFile;
		return $this;
	}

	public function setLibreSignFileFromNode(Node $node): self {
		$libreSignFile = $this->getLibresignFile($node->getId());
		$this->setLibreSignFile($libreSignFile);
		$this->setFileToSign($node);
		return $this;
	}

	public function setUserUniqueIdentifier(string $identifier): self {
		$this->userUniqueIdentifier = $identifier;
		return $this;
	}

	public function setFriendlyName(string $friendlyName): self {
		$this->friendlyName = $friendlyName;
		return $this;
	}

	/**
	 * @return static
	 */
	public function setSignRequest(SignRequestEntity $signRequest): self {
		$this->signRequest = $signRequest;
		return $this;
	}

	/**
	 * @return static
	 */
	public function setSignWithoutPassword(bool $signWithoutPassword): self {
		$this->signWithoutPassword = $signWithoutPassword;
		return $this;
	}

	/**
	 * @return static
	 */
	public function setPassword(?string $password = null): self {
		$this->password = $password;
		return $this;
	}

	/**
	 * @return static
	 */
	public function setVisibleElements(array $list): self {
		$fileElements = $this->fileElementMapper->getByFileIdAndSignRequestId($this->signRequest->getFileId(), $this->signRequest->getId());
		foreach ($fileElements as $fileElement) {
			$element = array_filter($list, function (array $element) use ($fileElement): bool {
				return $element['documentElementId'] === $fileElement->getId();
			});
			if ($element) {
				$c = current($element);
				$userElement = $this->userElementMapper->findOne(['id' => $c['profileElementId']]);
			} else {
				$userElement = $this->userElementMapper->findOne([
					'user_id' => $this->signRequest->getUserId(),
					'type' => $fileElement->getType(),
				]);
			}
			try {
				$nodeId = $userElement->getFileId();

				$mountsContainingFile = $this->userMountCache->getMountsForFileId($nodeId);
				foreach ($mountsContainingFile as $fileInfo) {
					$this->root->getByIdInPath($nodeId, $fileInfo->getMountPoint());
				}
				/** @var \OCP\Files\File[] */
				$node = $this->root->getById($nodeId);
				if (!$node) {
					throw new \Exception('empty');
				}
				$node = $node[0];
			} catch (\Throwable $th) {
				throw new LibresignException($this->l10n->t('You need to define a visible signature or initials to sign this document.'));
			}
			$tempFile = $this->tempManager->getTemporaryFile('.png');
			file_put_contents($tempFile, $node->getContent());
			$visibleElements = new VisibleElementAssoc(
				$fileElement,
				$userElement,
				$tempFile
			);
			$this->elements[] = $visibleElements;
		}
		return $this;
	}

	public function sign(): File {
		$fileToSign = $this->getFileToSing($this->libreSignFile);
		$pfxFile = $this->getPfxFile();
		switch ($fileToSign->getExtension()) {
			case 'pdf':
				$signedFile = $this->pkcs12Handler
					->setInputFile($fileToSign)
					->setCertificate($pfxFile)
					->setVisibleElements($this->elements)
					->setPassword($this->password)
					->sign();
				break;
			default:
				$signedFile = $this->pkcs7Handler
					->setInputFile($fileToSign)
					->setCertificate($pfxFile)
					->setPassword($this->password)
					->sign();
		}

		$this->signRequest->setSigned(time());
		if ($this->signRequest->getId()) {
			$this->signRequestMapper->update($this->signRequest);
		} else {
			$this->signRequestMapper->insert($this->signRequest);
		}

		$this->libreSignFile->setSignedNodeId($signedFile->getId());
		$allSigned = $this->updateStatus();
		$this->fileMapper->update($this->libreSignFile);

		$this->eventDispatcher->dispatchTyped(new SignedEvent($this, $signedFile, $allSigned));

		return $signedFile;
	}

	public function storeUserMetadata(array $metadata = []): self {
		$collectMetadata = $this->config->getAppValue(Application::APP_ID, 'collect_metadata') ? true : false;
		if (!$collectMetadata || !$metadata) {
			return $this;
		}
		$this->signRequest->setMetadata($metadata);
		$this->signRequestMapper->update($this->signRequest);
		return $this;
	}

	private function updateStatus(): bool {
		$signers = $this->signRequestMapper->getByFileId($this->signRequest->getFileId());
		$total = array_reduce($signers, function ($carry, $signer) {
			$carry += $signer->getSigned() ? 1 : 0;
			return $carry;
		});
		if (count($signers) === $total && $this->libreSignFile->getStatus() !== FileEntity::STATUS_SIGNED) {
			$this->libreSignFile->setStatus(FileEntity::STATUS_SIGNED);
			return true;
		}
		return false;
	}

	private function getPfxFile(): string {
		if ($this->signWithoutPassword) {
			$tempPassword = sha1((string) time());
			$this->setPassword($tempPassword);
			try {
				return $this->pkcs12Handler->generateCertificate(
					['identify' => $this->userUniqueIdentifier, 'name' => $this->friendlyName],
					$tempPassword,
					$this->friendlyName,
					true
				);
			} catch (TypeError $e) {
				throw new LibresignException($this->l10n->t('Failure to generate certificate'));
			} catch (EmptyRootCertificateException $e) {
				throw new LibresignException($this->l10n->t('Empty root certificate data'));
			} catch (InvalidArgumentException $e) {
				throw new LibresignException($this->l10n->t('Invalid data to generate certificate'));
			} catch (\Throwable $th) {
				throw new LibresignException($this->l10n->t('Failure on generate certificate'));
			}
		}
		return $this->pkcs12Handler->getPfx();
	}

	private function setFileToSign(Node $file): void {
		$this->fileToSign = $file;
	}

	/**
	 * Get file to sign
	 *
	 * @throws LibresignException
	 */
	public function getFileToSing(FileEntity $libresignFile): \OCP\Files\Node {
		if ($this->fileToSign) {
			$originalFile = $this->fileToSign;
		} else {
			$nodeId = $libresignFile->getNodeId();

			$mountsContainingFile = $this->userMountCache->getMountsForFileId($nodeId);
			foreach ($mountsContainingFile as $fileInfo) {
				$this->root->getByIdInPath($nodeId, $fileInfo->getMountPoint());
			}
			$originalFile = $this->root->getById($nodeId);
			if (count($originalFile) < 1) {
				throw new LibresignException($this->l10n->t('File not found'));
			}
			$originalFile = current($originalFile);
		}
		if ($originalFile->getExtension() === 'pdf') {
			return $this->getPdfToSign($libresignFile, $originalFile);
		}
		return $originalFile;
	}

	public function getLibresignFile(?int $fileId, ?string $signRequestUuid = null): FileEntity {
		try {
			if ($fileId) {
				$libresignFile = $this->fileMapper->getByFileId($fileId);
			} elseif ($signRequestUuid) {
				$signRequest = $this->signRequestMapper->getByUuid($signRequestUuid);
				$libresignFile = $this->fileMapper->getById($signRequest->getFileId());
			} else {
				throw new \Exception('Invalid arguments');
			}
		} catch (DoesNotExistException $th) {
			throw new LibresignException($this->l10n->t('File not found'), 1);
		}
		return $libresignFile;
	}

	public function requestCode(SignRequestEntity $signRequest, IUser $user): string {
		return $this->signMethod->requestCode($signRequest, $user);
	}

	public function getSignRequestToSign(FileEntity $libresignFile, IUser $user): SignRequestEntity {
		$this->validateHelper->fileCanBeSigned($libresignFile);
		try {
			$signRequests = $this->signRequestMapper->getByFileId($libresignFile->getId());

			$signRequest = array_reduce($signRequests, function (?SignRequestEntity $carry, SignRequestEntity $signRequest) use ($user): ?SignRequestEntity {
				$identifyMethods = $this->identifyMethodMapper->getIdentifyMethodsFromSignRequestId($signRequest->getId());
				$found = array_filter($identifyMethods, function (IdentifyMethod $identifyMethod) use ($user) {
					if ($identifyMethod->getIdentifierKey() === IdentifyMethodService::IDENTIFY_EMAIL
						&& (
							$identifyMethod->getIdentifierValue() === $user->getUID()
							|| $identifyMethod->getIdentifierValue() === $user->getEMailAddress()
						)
					) {
						return true;
					}
					if ($identifyMethod->getIdentifierKey() === IdentifyMethodService::IDENTIFY_ACCOUNT
						&& $identifyMethod->getIdentifierValue() === $user->getUID()
					) {
						return true;
					}
					return false;
				});
				if (count($found) > 0) {
					return $signRequest;
				}
				return $carry;
			});

			if ($signRequest->getSigned()) {
				throw new LibresignException($this->l10n->t('File already signed by you'), 1);
			}
		} catch (DoesNotExistException $th) {
			try {
				$accountFile = $this->accountFileMapper->getByFileId($libresignFile->getId());
			} catch (\Throwable $th) {
				throw new LibresignException($this->l10n->t('Invalid data to sign file'), 1);
			}
			$this->validateHelper->userCanApproveValidationDocuments($user);
			$signRequest = new SignRequestEntity();
			$signRequest->setFileId($libresignFile->getId());
			$signRequest->setEmail($user->getEMailAddress());
			$signRequest->setDisplayName($user->getDisplayName());
			$signRequest->setUserId($user->getUID());
			$signRequest->setUuid(UUIDUtil::getUUID());
			$signRequest->setCreatedAt(time());
		}
		return $signRequest;
	}

	/**
	 * @psalm-suppress MixedReturnStatement
	 * @psalm-suppress InvalidReturnStatement
	 * @psalm-suppress MixedMethodCall
	 */
	private function getPdfToSign(FileEntity $fileData, File $originalFile): File {
		if ($fileData->getSignedNodeId()) {
			$nodeId = $fileData->getSignedNodeId();

			$mountsContainingFile = $this->userMountCache->getMountsForFileId($nodeId);
			foreach ($mountsContainingFile as $fileInfo) {
				$this->root->getByIdInPath($nodeId, $fileInfo->getMountPoint());
			}
			$fileToSign = $this->root->getById($nodeId);
			/** @var \OCP\Files\File */
			$fileToSign = current($fileToSign);
		} else {
			$signedFilePath = preg_replace(
				'/' . $originalFile->getExtension() . '$/',
				$this->l10n->t('signed') . '.' . $originalFile->getExtension(),
				$originalFile->getPath()
			);

			$footer = $this->pkcs12Handler->getFooter($originalFile, $fileData->getUuid());
			if ($footer) {
				$background = $this->tempManager->getTemporaryFile('signed.pdf');
				file_put_contents($background, $footer);

				$dest = $this->tempManager->getTemporaryFile('signed.pdf');
				file_put_contents($dest, $originalFile->getContent());

				$pdftkPath = $this->config->getAppValue(Application::APP_ID, 'pdftk_path');
				$pdf = new Pdf();
				$command = new Command();
				$command->setCommand($pdftkPath);
				$pdf->setCommand($command);
				$pdf->addFile($dest);
				$buffer = $pdf->stamp($background)
					->toString($dest);
			} else {
				$buffer = $originalFile->getContent($originalFile);
			}
			/** @var \OCP\Files\File */
			$fileToSign = $this->root->newFile($signedFilePath);
			$fileToSign->putContent($buffer);
		}
		return $fileToSign;
	}

	/**
	 * @throws DoesNotExistException
	 */
	public function getSignRequest(string $uuid): SignRequestEntity {
		return $this->signRequestMapper->getByUuid($uuid);
	}

	/**
	 * @throws DoesNotExistException
	 */
	public function getFile(int $signRequestId): FileEntity {
		return $this->fileMapper->getById($signRequestId);
	}

	/**
	 * @throws DoesNotExistException
	 */
	public function getFileByUuid(string $uuid): FileEntity {
		return $this->fileMapper->getByUuid($uuid);
	}

	public function getAccountFileById(int $fileId): AccountFile {
		return $this->accountFileMapper->getByFileId($fileId);
	}

	public function getNextcloudFile(int $nodeId): File {
		$mountsContainingFile = $this->userMountCache->getMountsForFileId($nodeId);
		foreach ($mountsContainingFile as $fileInfo) {
			$this->root->getByIdInPath($nodeId, $fileInfo->getMountPoint());
		}
		$fileToSign = $this->root->getById($nodeId);
		if (count($fileToSign) < 1) {
			throw new LibresignException(json_encode([
				'action' => JSActions::ACTION_DO_NOTHING,
				'errors' => [$this->l10n->t('File not found')],
			]), AppFrameworkHttp::STATUS_NOT_FOUND);
		}
		/** @var File */
		$fileToSign = $fileToSign[0];
		return $fileToSign;
	}

	public function validateSigner($uuid, $user): void {
		$this->validateHelper->validateSigner($uuid, $user);
	}

	/**
	 * @return (array|int|mixed)[]
	 * @psalm-return array{action?: int, user?: array{name: mixed}, sign?: array{pdf: array{file?: File, nodeId?: mixed, url?: mixed, base64?: string}|null, uuid: mixed, filename: mixed, description: mixed}, errors?: non-empty-list<mixed>, redirect?: mixed, settings?: array{accountHash: string}}
	 */
	public function getInfoOfFileToSignUsingSignRequestUuid(?string $uuid, ?IUser $user, string $formatOfPdfOnSign): array {
		$return = [];
		if (!$uuid) {
			return $return;
		}
		$signRequest = $this->signRequestMapper->getByUuid($uuid);
		$fileEntity = $this->fileMapper->getById($signRequest->getFileId());

		$nodeId = $fileEntity->getNodeId();

		$mountsContainingFile = $this->userMountCache->getMountsForFileId($nodeId);
		foreach ($mountsContainingFile as $fileInfo) {
			$this->root->getByIdInPath($nodeId, $fileInfo->getMountPoint());
		}
		$fileToSign = $this->root->getById($nodeId);
		if (count($fileToSign) < 1) {
			throw new LibresignException(json_encode([
				'action' => JSActions::ACTION_DO_NOTHING,
				'errors' => [$this->l10n->t('File not found')],
			]));
		}
		/** @var File */
		$fileToSign = $fileToSign[0];
		$return = $this->getFileData($fileEntity, $user, $signRequest);
		$return['sign']['pdf'] = $this->getFileUrl($formatOfPdfOnSign, $fileEntity, $fileToSign, $uuid);
		return $return;
	}

	public function getInfoOfFileToSignUsingFileUuid(?string $uuid, ?IUser $user, string $formatOfPdfOnSign): array {
		$return = [];
		if (!$uuid) {
			return $return;
		}
		try {
			$fileEntity = $this->fileMapper->getByUuid($uuid);
			$this->accountFileMapper->getByFileId($fileEntity->getId());
		} catch (DoesNotExistException $e) {
			throw new LibresignException(json_encode([
				'action' => JSActions::ACTION_DO_NOTHING,
				'errors' => [$this->l10n->t('Invalid UUID')],
			]));
		}
		$this->throwIfAlreadySigned($fileEntity);
		try {
			$this->validateHelper->userCanApproveValidationDocuments($user);
		} catch (LibresignException $e) {
			throw new LibresignException(json_encode([
				'action' => JSActions::ACTION_DO_NOTHING,
				'errors' => [$e->getMessage()],
			]));
		}

		$nodeId = $fileEntity->getNodeId();

		$mountsContainingFile = $this->userMountCache->getMountsForFileId($nodeId);
		foreach ($mountsContainingFile as $fileInfo) {
			$this->root->getByIdInPath($nodeId, $fileInfo->getMountPoint());
		}
		$fileToSign = $this->root->getById($nodeId);
		if (count($fileToSign) < 1) {
			throw new LibresignException(json_encode([
				'action' => JSActions::ACTION_DO_NOTHING,
				'errors' => [$this->l10n->t('File not found')],
			]));
		}
		/** @var File */
		$fileToSign = current($fileToSign);
		$return = $this->getFileData($fileEntity, $user);
		$return['sign']['pdf'] = $this->getFileUrl($formatOfPdfOnSign, $fileEntity, $fileToSign, $uuid);
		return $return;
	}

	private function throwIfAlreadySigned(FileEntity $fileEntity, ?SignRequestEntity $signRequest = null): void {
		if ($fileEntity->getStatus() === FileEntity::STATUS_SIGNED
			|| (!is_null($signRequest) && $signRequest->getSigned())
		) {
			throw new LibresignException(json_encode([
				'action' => JSActions::ACTION_SHOW_ERROR,
				'errors' => [$this->l10n->t('File already signed.')],
				'uuid' => $fileEntity->getUuid(),
			]));
		}
	}

	public function getFileData(FileEntity $fileData, ?IUser $user, ?SignRequestEntity $signRequest = null): array {
		$return['action'] = JSActions::ACTION_SIGN;
		$return['sign'] = [
			'uuid' => $fileData->getUuid(),
			'filename' => $fileData->getName()
		];
		if ($signRequest) {
			$return['user']['name'] = $signRequest->getDisplayName();
			$return['sign']['description'] = $signRequest->getDescription();
			$return['settings']['identifyMethods'] = array_map(function (IdentifyMethod $identifyMethod): array {
				return [
					'mandatory' => $identifyMethod->getMandatory(),
					'identifiedAtDate' => $identifyMethod->getIdentifiedAtDate(),
					'method' => $identifyMethod->getMethod(),
				];
			}, $this->identifyMethodMapper->getIdentifyMethodsFromSignRequestId($signRequest->getId()));
		} else {
			$return['user']['name'] = $user->getDisplayName();
		}
		return $return;
	}

	/**
	 * @psalm-return array{file?: File, nodeId?: int, url?: string, base64?: string}
	 */
	public function getFileUrl(string $format, FileEntity $fileEntity, File $fileToSign, string $uuid): array {
		$url = [];
		switch ($format) {
			case 'base64':
				$url = ['base64' => base64_encode($fileToSign->getContent())];
				break;
			case 'url':
				try {
					$this->accountFileMapper->getByFileId($fileEntity->getId());
					$url = ['url' => $this->urlGenerator->linkToRoute('libresign.page.getPdf', ['uuid' => $uuid])];
				} catch (DoesNotExistException $e) {
					$url = ['url' => $this->urlGenerator->linkToRoute('libresign.page.getPdfUser', ['uuid' => $uuid])];
				}
				break;
			case 'nodeId':
				$url = ['nodeId' => $fileToSign->getId()];
				break;
			case 'file':
				$url = ['file' => $fileToSign];
				break;
		}
		return $url;
	}
}
