<?php

namespace GeorgRinger\LoginLink\Controller;

use GeorgRinger\LoginLink\Exception\UserValidationException;
use GeorgRinger\LoginLink\Repository\TokenRepository;
use GeorgRinger\LoginLink\Service\TokenGenerator;
use Psr\Http\Message\ResponseInterface;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mime\Address;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\Exception;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Mail\FluidEmail;
use TYPO3\CMS\Core\Mail\Mailer;
use TYPO3\CMS\Core\Utility\ArrayUtility;
use TYPO3\CMS\Core\Utility\Exception\MissingArrayPathException;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Configuration\ConfigurationManager;
use TYPO3\CMS\Extbase\Configuration\ConfigurationManagerInterface;
use TYPO3\CMS\Extbase\Http\ForwardResponse;
use TYPO3\CMS\Extbase\Mvc\Controller\ActionController;
use TYPO3\CMS\Extbase\Mvc\Exception\StopActionException;
use TYPO3\CMS\Extbase\Mvc\View\ViewInterface;
use TYPO3\CMS\Extbase\Utility\LocalizationUtility;

class PluginController extends ActionController
{
    protected array $settingsAsTypoScriptArray = [];

    /**
     * @param ConfigurationManagerInterface $configurationManager
     */
    public function __construct(
        private readonly LanguageServiceFactory $LanguageServiceFactory,
        private readonly TokenGenerator $tokenGenerator,
        private readonly TokenREPOSITORY $tokenRepository,
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
            $this->sendMailToFrontendUser($userId, $email);
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
            $users = $qb->executeQuery()->fetchAllKeyValue();
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


    /**
     * @throws Exception
     * @throws TransportExceptionInterface
     */
    public function sendMailToFrontendUser(int $recordId, string $receiverEmailAddress): void
    {
//        $this->getLanguageService()->includeLLFile('EXT:login_link/Resources/Private/Language/locallang.xlf');

        $authType = 'fe';
        $token = $this->tokenGenerator->generate();
        $this->tokenRepository->add(
            $recordId,
            $authType,
            $token,
            0,
            15
        );
        $url = GeneralUtility::makeInstance(\TYPO3\CMS\Extbase\Mvc\Web\Routing\UriBuilder::class)
            ->setRequest($this->request)
            ->setTargetPageUid($GLOBALS['TYPO3_REQUEST']->getAttribute('frontend.controller')->id)
            ->setArguments(['byToken' => $token, 'logintype' => 'login'])
            ->setCreateAbsoluteUri(true)
            ->buildFrontendUri();

        $email = GeneralUtility::makeInstance(FluidEmail::class);
        $email->setRequest($GLOBALS['TYPO3_REQUEST']);
        $mailFromAddress = ($this->settings['email']['fromAddress'] ?? false) ?: ($this->loginlinkExtensionConfiguration['pluginMailFromAddress'] ?? false) ?: ($GLOBALS['TYPO3_CONF_VARS']['MAIL']['defaultMailFromAddress'] ?? false);
        $mailFromName = ($this->settings['email']['fromName'] ?? false) ?: ($this->loginlinkExtensionConfiguration['pluginMailFromName'] ?? false) ?: ($GLOBALS['TYPO3_CONF_VARS']['MAIL']['defaultMailFromName'] ?? false);
        if(!$mailFromAddress) {
            throw new Exception('Either plugin.tx_loginlink_magicloginlinkform.settings.mail.fromAddress, pluginMailFromAddress of the extension configuration or $GLOBALS[\'TYPO3_CONF_VARS\'][\'MAIL\'][\'defaultMailFromAddress\'] needs to be configured to be able to send an e-email.');
        }
        $webSiteTitle = $GLOBALS['TYPO3_REQUEST']->getAttribute('site')->getConfiguration()['websiteTitle'] ?? '';

        $email
            ->to($receiverEmailAddress)
            ->from(new Address($mailFromAddress, $mailFromName))
            ->subject(LocalizationUtility::translate('plugin.email_subject','login_link', [$webSiteTitle]))
            ->format('html') // only HTML mail
            ->setTemplate('MagicLoginLink')
            ->assign('headline', LocalizationUtility::translate('plugin.email_subject','login_link', [$webSiteTitle]))
            ->assign('introduction', LocalizationUtility::translate('plugin.email_introduction','login_link', [$receiverEmailAddress]))
            ->assign('content', LocalizationUtility::translate('plugin.email_content','login_link', [$webSiteTitle]))
            ->assign('email', $receiverEmailAddress)
            ->assign('loginUrl', $url)
            ->assign('site', $GLOBALS['TYPO3_REQUEST']->getAttribute('site')->getConfiguration());
        GeneralUtility::makeInstance(Mailer::class)->send($email);
    }

}