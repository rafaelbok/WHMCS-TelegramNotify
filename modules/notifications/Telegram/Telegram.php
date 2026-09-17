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
    const MAX_MESSAGE_LENGTH = 4096;

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
        $messageContent = $this->buildNotificationMessage(
            $notification->getTitle(),
            $notification->getMessage(),
            $notification->getUrl()
        );

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
        $message = $this->limitMessage($message, 'truncate_safe');

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
     * Build a notification message within Telegram's 4096-character text limit.
     * The body is truncated first so the title and URL remain available.
     *
     * @param string $title
     * @param string $message
     * @param string $url
     *
     * @return string
     */
    private function buildNotificationMessage($title, $message, $url)
    {
        $prefix = $title . "\n\n";
        $suffix = "\n\nOpen » " . $url;
        $messageContent = $prefix . $message . $suffix;
        $messageLength = $this->unicodeLength($messageContent);

        if ($messageLength <= self::MAX_MESSAGE_LENGTH) {
            return $messageContent;
        }

        $bodyBudget = self::MAX_MESSAGE_LENGTH
            - $this->unicodeLength($prefix)
            - $this->unicodeLength($suffix);

        if ($bodyBudget > 0) {
            $this->logMessageLimit($messageLength, 'truncate_preserving_title_and_url');

            return $prefix . $this->truncateSafely($message, $bodyBudget) . $suffix;
        }

        // Extremely long titles or URLs cannot both be retained in full. Keep a
        // safe, explicit truncation of each rather than sending an oversized value.
        $titleBudget = (int) floor((self::MAX_MESSAGE_LENGTH - $this->unicodeLength("\n\nOpen » ")) / 2);
        $urlBudget = self::MAX_MESSAGE_LENGTH - $this->unicodeLength("\n\nOpen » ") - $titleBudget;
        $this->logMessageLimit($messageLength, 'truncate_title_and_url_safely');

        return $this->truncateSafely($title, $titleBudget)
            . "\n\nOpen » "
            . $this->truncateSafely($url, $urlBudget);
    }

    /**
     * Limit arbitrary messages passed directly to the sender.
     *
     * @param string $message
     * @param string $strategy
     *
     * @return string
     */
    private function limitMessage($message, $strategy)
    {
        $messageLength = $this->unicodeLength($message);

        if ($messageLength <= self::MAX_MESSAGE_LENGTH) {
            return $message;
        }

        $this->logMessageLimit($messageLength, $strategy);

        return $this->truncateSafely($message, self::MAX_MESSAGE_LENGTH);
    }

    /**
     * Truncate on Unicode code-point boundaries, treating Markdown escapes and
     * HTML entities as indivisible units so formatted output is not cut mid-token.
     *
     * @param string $message
     * @param int    $maximumLength
     *
     * @return string
     */
    private function truncateSafely($message, $maximumLength)
    {
        if ($this->unicodeLength($message) <= $maximumLength) {
            return $message;
        }

        if ($maximumLength < 1) {
            return '';
        }

        $ellipsis = '…';
        $contentBudget = $maximumLength - $this->unicodeLength($ellipsis);
        if ($contentBudget < 1) {
            return $ellipsis;
        }

        preg_match_all(
            '/\\\\.|&(?:#[0-9]+|#x[0-9A-Fa-f]+|[A-Za-z][A-Za-z0-9]+);|./us',
            $message,
            $matches
        );

        $truncated = '';
        $length = 0;
        foreach ($matches[0] as $token) {
            $tokenLength = $this->unicodeLength($token);
            if ($length + $tokenLength > $contentBudget) {
                break;
            }

            $truncated .= $token;
            $length += $tokenLength;
        }

        return $truncated . $ellipsis;
    }

    /**
     * Count Unicode code points without depending on the mbstring extension.
     *
     * @param string $message
     *
     * @return int
     */
    private function unicodeLength($message)
    {
        if (preg_match_all('/./us', $message, $matches) !== false) {
            return count($matches[0]);
        }

        return strlen($message);
    }

    /**
     * Record only diagnostics that are safe for notification content.
     */
    private function logMessageLimit($messageLength, $strategy)
    {
        error_log(sprintf(
            'Telegram notification content limited: length=%d, strategy=%s.',
            $messageLength,
            $strategy
        ));
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
