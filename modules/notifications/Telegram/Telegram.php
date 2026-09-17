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

    const MAX_SEND_ATTEMPTS = 3;
    const RETRY_BASE_DELAY_MICROSECONDS = 250000;
    const RETRY_MAX_DELAY_MICROSECONDS = 2000000;

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

        for ($attempt = 1; $attempt <= self::MAX_SEND_ATTEMPTS; $attempt++) {
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
                if ($this->retrySend($attempt, 'connection')) {
                    continue;
                }

                // Do not expose the request URL because it contains the bot token.
                throw new Exception('Unable to connect to the Telegram API.');
            }

            $statusCode = $response->getStatusCode();
            $responseBody = (string) $response->getBody();
            $responseData = json_decode($responseBody, true);

            if ($statusCode === 429) {
                $retryAfter = $this->getRetryAfter($responseData);
                if ($this->retrySend($attempt, 'rate_limit', $retryAfter)) {
                    continue;
                }
            } elseif ($statusCode >= 500 && $statusCode <= 599) {
                if ($this->retrySend($attempt, 'server_error')) {
                    continue;
                }
            }

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

            return;
        }
    }

    /**
     * Log and schedule a bounded retry for a known transient failure.
     *
     * @param int      $attempt
     * @param string   $category
     * @param int|null $retryAfter Seconds provided by Telegram for HTTP 429.
     *
     * @return bool Whether another attempt should be made.
     */
    private function retrySend($attempt, $category, $retryAfter = null)
    {
        if ($attempt >= self::MAX_SEND_ATTEMPTS) {
            $this->logRetry($attempt, $category, false);

            return false;
        }

        $this->logRetry($attempt, $category, true);

        if ($retryAfter !== null) {
            sleep($retryAfter);
        } else {
            $maximumDelay = min(
                self::RETRY_MAX_DELAY_MICROSECONDS,
                self::RETRY_BASE_DELAY_MICROSECONDS * (1 << ($attempt - 1))
            );
            usleep(random_int((int) (self::RETRY_BASE_DELAY_MICROSECONDS / 2), $maximumDelay));
        }

        return true;
    }

    /**
     * Return Telegram's rate-limit delay only when it is a usable non-negative integer.
     *
     * @param mixed $responseData
     *
     * @return int|null
     */
    private function getRetryAfter($responseData)
    {
        if (!is_array($responseData)
            || !isset($responseData['parameters']['retry_after'])
            || filter_var($responseData['parameters']['retry_after'], FILTER_VALIDATE_INT) === false
            || (int) $responseData['parameters']['retry_after'] < 0
        ) {
            return null;
        }

        return (int) $responseData['parameters']['retry_after'];
    }

    /**
     * Keep retry logs useful without logging request URLs, tokens, or message content.
     */
    private function logRetry($attempt, $category, $willRetry)
    {
        error_log(sprintf(
            'Telegram notification delivery failure: attempt %d/%d, category=%s, will_retry=%s.',
            $attempt,
            self::MAX_SEND_ATTEMPTS,
            $category,
            $willRetry ? 'yes' : 'no'
        ));
    }
}
