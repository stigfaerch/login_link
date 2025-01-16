<?php

namespace GeorgRinger\LoginLink\Controller;

use GeorgRinger\LoginLink\Exception\UserValidationException;
use GeorgRinger\LoginLink\Service\SendMail;
use Psr\Http\Message\ResponseInterface;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\Exception;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Utility\ArrayUtility;
use TYPO3\CMS\Core\Utility\Exception\MissingArrayPathException;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Configuration\ConfigurationManager;
use TYPO3\CMS\Extbase\Configuration\ConfigurationManagerInterface;
use TYPO3\CMS\Extbase\Http\ForwardResponse;
use TYPO3\CMS\Extbase\Mvc\Controller\ActionController;
use TYPO3\CMS\Extbase\Mvc\Exception\StopActionException;
use TYPO3\CMS\Extbase\Mvc\View\ViewInterface;

class PluginController extends ActionController
{
    protected array $settingsAsTypoScriptArray = [];

    /**
     * @param ConfigurationManagerInterface $configurationManager
     */
    public function __construct(
        private readonly LanguageServiceFactory $LanguageServiceFactory,
    ){}

    protected function initializeAction(): void
    {
        parent::initializeAction();
        $this->configurationManager = GeneralUtility::makeInstance(ConfigurationManager::class);
    }

    public function showFormAction(): ResponseInterface
    {
        $email = $this->getEmailAddress();
        try {
            if ($email && $this->getUserIdFromEmail($email)) {
                $response = new ForwardResponse('showFormPredefinedEmail');
                return $response->withArguments(['email' => $email]);
            }
        } catch (\Doctrine\DBAL\Driver\Exception $e) {
        } catch (UserValidationException $e) {
            $this->view->assignMultiple([
                'errorMessage' => $this->request->getArguments()['errorMessage'] ?? $e->getMessage(),
                'email' => $email,
                'emailFromCObjectData' => $this->request->getAttribute('currentContentObject')->data['email'] ?? null
            ]);
            if($userObject = $GLOBALS['TSFE']->fe_user->user ?? false) {
                $this->view->assignMultiple([
                    'user' => $userObject,
                    'usernameAndEmail' => $userObject['username'] == $userObject['email'] ? $userObject['email'] : "{$userObject['username']} ({$userObject['email']})"
                ]);
            }

        }
        return $this->htmlResponse();
    }

    private function getEmailAddress($email = null): ?string
    {
        if ($email === null) {
            $email = $this->request->getArguments()['email'] ?? null;
        }

        if ($email === null) {
            $email = $this->request->getAttribute('currentContentObject')->data['email'] ?? null;
        }

        if ($email === null && ($postVar = $this->request->getAttribute('currentContentObject')->data['postVar'] ?? null)) {
            try {
                $email = ArrayUtility::getValueByPath($GLOBALS['_POST'], $postVar, '.') ?? null;
            } catch (MissingArrayPathException $e) {
            }
        }

        return $email;
    }

    public function showFormPredefinedEmailAction(string $email = ''): \Psr\Http\Message\ResponseInterface
    {
        $this->view->assignMultiple([
            'errorMessage' => $this->request->getArguments()['errorMessage'] ?? null,
            'email' => $this->getEmailAddress($email)
        ]);
        if($userObject = $GLOBALS['TSFE']->fe_user->user ?? false) {
            $this->view->assignMultiple([
                'user' => $userObject,
                'usernameAndEmail' => $userObject['username'] == $userObject['email'] ? $userObject['email'] : "{$userObject['username']} ({$userObject['email']})"
            ]);
        }
        return $this->htmlResponse();

    }


    /**
     * @throws Exception
     * @throws StopActionException
     * @throws \Doctrine\DBAL\Driver\Exception
     */
    public function sendMailAction(string $email = ''): ResponseInterface
    {
        try {
            $userId = $this->getUserIdFromEmail($email);
            GeneralUtility::makeInstance(SendMail::class)->sendMailToFrontendUser($userId, $email, $this->settings);
        } catch (TransportExceptionInterface $exception) {
            $this->redirect('showForm', null, null, ['email' => $email,
                'errorMessage' => $this->getTranslatedLabel('LLL:EXT:login_link/Resources/Private/Language/locallang.xlf:plugin.mailer_sending_error')]);
            error_log($exception->getMessage());
        } catch (UserValidationException $exception) {
            $this->redirect('showForm', null, null, ['email' => $email,
                'errorMessage' => $exception->getMessage()]);
        }
        return $this->htmlResponse();
    }

    /**
     * @throws \Doctrine\DBAL\Driver\Exception|UserValidationException
     */
    private function getUserIdFromEmail(string $email): int
    {
        if (!GeneralUtility::validEmail($email)) {
            $validationError = ['message' => $this->getTranslatedLabel('LLL:EXT:login_link/Resources/Private/Language/locallang.xlf:plugin.validation_email_syntax_error'), 'code' => 1736953771];
        } else {
            $qb = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable('fe_users');
            $qb->getRestrictions()->removeAll()->add(GeneralUtility::makeInstance(DeletedRestriction::class));
            $qb->select('uid', 'disable')->from('fe_users');
            $qb->andWhere($qb->expr()->eq('email', $qb->createNamedParameter($email)));
            if (($pageId = $this->getStoragePid()) !== 0) {
                $qb->andWhere($qb->expr()->eq('pid', $qb->createNamedParameter($pageId)));
            }
            $users = $qb->execute()->fetchAllKeyValue();
            if (count($users) === 0) {
                $validationError = ['message' => $this->getTranslatedLabel('LLL:EXT:login_link/Resources/Private/Language/locallang.xlf:plugin.validation_no_users_found_error'), 'code' => 1704878341];
            } elseif (count($users) > 1) {
                $validationError = ['message' => $this->getTranslatedLabel('LLL:EXT:login_link/Resources/Private/Language/locallang.xlf:plugin.validation_multiple_users_found_error'), 'code' => 1704878342];
            } elseif (current($users) === 1) {
                $validationError = ['message' => $this->getTranslatedLabel('LLL:EXT:login_link/Resources/Private/Language/locallang.xlf:plugin.validation_disabled_user_found_error'), 'code' => 1704878343];
            } else {
                return (int)key($users);
            }
        }
        throw new UserValidationException($validationError['message'], $validationError['code']);
    }

    private function getTranslatedLabel(string $key): string
    {
        $language =
            $this->request->getAttribute('language')
            ?? $this->request->getAttribute('site')->getDefaultLanguage();
        $languageService = $this->LanguageServiceFactory->createFromSiteLanguage($language);

        return $languageService->sL($key);
    }

    protected function getStoragePid(): int
    {
        return $this->configurationManager->getConfiguration(ConfigurationManagerInterface::CONFIGURATION_TYPE_FRAMEWORK)['persistence']['storagePid'] ?? 0;
    }
}