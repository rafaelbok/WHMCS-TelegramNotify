<?php

namespace WHMCS\Module\Notification\Telegram;

use GuzzleHttp\Exception\TransferException;
use WHMCS\Exception;
use WHMCS\Http\Client\HttpClient;
use WHMCS\Module\Contracts\NotificationModuleInterface;
use WHMCS\Module\Notification\DescriptionTrait;
use WHMCS\Notification\Contracts\NotificationInterface;

class Telegram implements NotificationModuleInterface
{
    use DescriptionTrait;

    public function __construct()
    {
        $this->setDisplayName('Telegram')
            ->setLogoFileName('logo.png');
    }

    public function settings()
    {
        return [
            'botToken' => [
                'FriendlyName' => 'Token',
                'Type' => 'text',
                'Description' => 'Token of the Telegram Bot.',
                'Placeholder' => ' ',
            ],
            'botChatID' => [
                'FriendlyName' => 'chatID',
                'Type' => 'text',
                'Description' => 'ChatID of the user/channel.',
                'Placeholder' => ' ',
            ],
        ];
    }

    public function testConnection($settings)
    {
        $this->sendTelegramMessage(
            $settings['botToken'],
            $settings['botChatID'],
            'Connected with WHMCS'
        );
    }

    public function notificationSettings()
    {
        return [];
    }

    public function getDynamicField($fieldName, $settings)
    {
        return [];
    }

    public function sendNotification(NotificationInterface $notification, $moduleSettings, $notificationSettings)
    {
        $messageContent = $notification->getTitle() . "\n\n"
            . $notification->getMessage() . "\n\n"
            . "Open » " . $notification->getUrl();

        $this->sendTelegramMessage(
            $moduleSettings['botToken'],
            $moduleSettings['botChatID'],
            $messageContent
        );
    }

    /**
     * Send a message through Telegram and validate both HTTP and API responses.
     *
     * @param string      $botToken
     * @param string      $chatId
     * @param string      $message
     * @param string|null $parseMode
     *
     * @throws Exception
     */
    private function sendTelegramMessage($botToken, $chatId, $message, $parseMode = null)
    {
        $client = new HttpClient();
        $formParams = [
            'chat_id' => $chatId,
            'text' => $message,
        ];

        if ($parseMode !== null) {
            $formParams['parse_mode'] = $parseMode;
        }

        // Keep message parameters out of the URL: the endpoint also contains the bot token.
        $endpoint = 'https://api.telegram.org/bot' . $botToken . '/sendMessage';

        try {
            $response = $client->post(
                $endpoint,
                [
                    'connect_timeout' => 5,
                    'timeout' => 10,
                    'http_errors' => false,
                    'form_params' => $formParams,
                ]
            );
        } catch (TransferException $exception) {
            // Do not expose the request URL because it contains the bot token.
            throw new Exception('Unable to connect to the Telegram API.');
        }

        $statusCode = $response->getStatusCode();
        $responseBody = (string) $response->getBody();
        $responseData = json_decode($responseBody, true);

        if (is_array($responseData) && isset($responseData['ok']) && $responseData['ok'] === false) {
            $errorCode = isset($responseData['error_code']) ? $responseData['error_code'] : 'unknown';
            $description = isset($responseData['description']) ? $responseData['description'] : 'Unknown error';
            $errorMessage = sprintf(
                'Telegram API error (error_code: %s, description: %s',
                $errorCode,
                $description
            );

            if (isset($responseData['parameters']['retry_after'])) {
                $errorMessage .= sprintf(', retry_after: %s', $responseData['parameters']['retry_after']);
            }

            if ($botToken !== '') {
                $errorMessage = str_replace($botToken, '[redacted]', $errorMessage);
            }

            throw new Exception($errorMessage . ').');
        }

        if ($statusCode < 200 || $statusCode >= 300) {
            throw new Exception(sprintf('Telegram API returned HTTP status %d.', $statusCode));
        }

        if (!is_array($responseData) || !isset($responseData['ok']) || $responseData['ok'] !== true) {
            throw new Exception('Telegram API returned an invalid response.');
        }
    }
}
