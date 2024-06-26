<?php

namespace GeorgRinger\LoginLink\Service;

use GeorgRinger\LoginLink\Repository\TokenRepository;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mime\Address;
use TYPO3\CMS\Core\Exception;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Mail\FluidEmail;
use TYPO3\CMS\Core\Mail\Mailer;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Utility\LocalizationUtility;

class SendMail
{
    protected TokenGenerator $tokenGenerator;
    protected TokenRepository $tokenRepository;

    protected array $loginlinkExtensionConfiguration;

    /**
     * @param TokenGenerator $tokenGenerator
     * @param TokenRepository $tokenRepository
     * @param array $loginlinkExtensionConfiguration
     */
    public function __construct(TokenGenerator $tokenGenerator, TokenRepository $tokenRepository, array $loginlinkExtensionConfiguration)
    {
        $this->tokenGenerator = $tokenGenerator;
        $this->tokenRepository = $tokenRepository;
        $this->loginlinkExtensionConfiguration = $loginlinkExtensionConfiguration;
    }

    /**
     * @param int $recordId
     * @param string $receiverEmailAddress
     * @param array $settings
     * @throws Exception
     * @throws TransportExceptionInterface
     */
    public function sendMailToFrontendUser(int $recordId, string $receiverEmailAddress, array $settings): void
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
            ->setTargetPageUid($GLOBALS['TYPO3_REQUEST']->getAttribute('frontend.controller')->id)
            ->setArguments(['byToken' => $token, 'logintype' => 'login'])
            ->setCreateAbsoluteUri(true)
            ->buildFrontendUri();

        $email = GeneralUtility::makeInstance(FluidEmail::class);
        $email->setRequest($GLOBALS['TYPO3_REQUEST']);
        $mailFromAddress = ($settings['email']['fromAddress'] ?? false) ?: ($this->loginlinkExtensionConfiguration['pluginMailFromAddress'] ?? false) ?: ($GLOBALS['TYPO3_CONF_VARS']['MAIL']['defaultMailFromAddress'] ?? false);
        $mailFromName = ($settings['email']['fromName'] ?? false) ?: ($this->loginlinkExtensionConfiguration['pluginMailFromName'] ?? false) ?: ($GLOBALS['TYPO3_CONF_VARS']['MAIL']['defaultMailFromName'] ?? false);
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

    /**
     * @return LanguageService
     */
    protected function getLanguageService(): LanguageService
    {
        return $GLOBALS['LANG'];
    }


}
