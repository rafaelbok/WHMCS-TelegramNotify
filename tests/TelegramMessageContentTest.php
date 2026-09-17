<?php

namespace GuzzleHttp\Exception {
    class TransferException extends \Exception
    {
    }
}

namespace WHMCS {
    class Exception extends \Exception
    {
    }
}

namespace WHMCS\Http\Client {
    class HttpClient
    {
        public static $lastRequest;
        public static $requests = [];
        public static $responseQueue = [];

        public function post($url, array $options)
        {
            self::$lastRequest = [$url, $options];
            self::$requests[] = self::$lastRequest;

            if (self::$responseQueue) {
                $response = array_shift(self::$responseQueue);
                if ($response instanceof \Exception) {
                    throw $response;
                }

                return $response;
            }

            return new Response();
        }
    }

    class Response
    {
        private $statusCode;
        private $body;

        public function __construct($statusCode = 200, $body = '{"ok":true}')
        {
            $this->statusCode = $statusCode;
            $this->body = $body;
        }

        public function getStatusCode()
        {
            return $this->statusCode;
        }

        public function getBody()
        {
            return $this->body;
        }
    }
}

namespace WHMCS\Module\Contracts {
    interface NotificationModuleInterface
    {
    }
}

namespace WHMCS\Module\Notification {
    trait DescriptionTrait
    {
        protected function setDisplayName($displayName)
        {
            return $this;
        }

        protected function setLogoFileName($logoFileName)
        {
            return $this;
        }
    }
}

namespace WHMCS\Notification\Contracts {
    interface NotificationInterface
    {
    }
}

namespace {
    use GuzzleHttp\Exception\TransferException;
    use WHMCS\Http\Client\HttpClient;
    use WHMCS\Http\Client\Response;
    use WHMCS\Module\Notification\Telegram\Telegram;
    use WHMCS\Notification\Contracts\NotificationInterface;

    require dirname(__DIR__) . '/modules/notifications/Telegram/Telegram.php';

    class TestNotification implements NotificationInterface
    {
        private $title;
        private $message;
        private $url;

        public function __construct($title, $message, $url)
        {
            $this->title = $title;
            $this->message = $message;
            $this->url = $url;
        }

        public function getTitle()
        {
            return $this->title;
        }

        public function getMessage()
        {
            return $this->message;
        }

        public function getUrl()
        {
            return $this->url;
        }
    }

    $cases = [
        [
            'Title_with *stars* [brackets] (parentheses) \\ and Unicode: Olá 世界',
            "Message_with *stars* [brackets] (parentheses) \\ and Unicode: café 🚀\nSecond line",
            'https://example.com/a_path/*value*/[item]/(detail)/back\\slash?label=Olá_世界',
        ],
        [
            "Multiline_title\nwith _underscores_ and *asterisks*",
            "First line\nSecond line with [square] and (round) brackets\nThird line ends in \\",
            "https://example.test/ümlaut_(value)?next=[page]&mark=*star*\\tail\nfragment",
        ],
    ];

    foreach ($cases as $caseNumber => $case) {
        list($title, $message, $url) = $case;
        $telegram = new Telegram();
        $telegram->sendNotification(
            new TestNotification($title, $message, $url),
            ['botToken' => 'test-token', 'botChatID' => 'test-chat'],
            []
        );

        list($requestUrl, $options) = HttpClient::$lastRequest;
        $formParams = $options['form_params'];
        $expected = $title . "\n\n" . $message . "\n\nOpen » " . $url;

        if ($requestUrl !== 'https://api.telegram.org/bottest-token/sendMessage') {
            throw new \RuntimeException('Unexpected request URL in case ' . $caseNumber);
        }

        if (strpos($requestUrl, '?') !== false) {
            throw new \RuntimeException('Request URL must not include message parameters in case ' . $caseNumber);
        }

        if ($formParams['chat_id'] !== 'test-chat') {
            throw new \RuntimeException('Chat ID must be sent as form data in case ' . $caseNumber);
        }

        if ($formParams['text'] !== $expected) {
            throw new \RuntimeException('Message content changed in case ' . $caseNumber);
        }

        if (array_key_exists('parse_mode', $formParams)) {
            throw new \RuntimeException('parse_mode must not be sent in case ' . $caseNumber);
        }
    }

    $telegram = new Telegram();
    $telegram->testConnection(['botToken' => 'test-token', 'botChatID' => 'test-chat']);
    list($requestUrl, $options) = HttpClient::$lastRequest;

    if ($requestUrl !== 'https://api.telegram.org/bottest-token/sendMessage'
        || $options['form_params'] !== [
            'chat_id' => 'test-chat',
            'text' => 'Connected with WHMCS',
        ]) {
        throw new \RuntimeException('Connection test must send its data as form parameters.');
    }

    $method = new \ReflectionMethod(Telegram::class, 'sendTelegramMessage');
    $method->setAccessible(true);
    $method->invoke($telegram, 'test-token', 'test-chat', 'Formatted message', 'MarkdownV2');
    list($requestUrl, $options) = HttpClient::$lastRequest;

    if ($requestUrl !== 'https://api.telegram.org/bottest-token/sendMessage'
        || $options['form_params'] !== [
            'chat_id' => 'test-chat',
            'text' => 'Formatted message',
            'parse_mode' => 'MarkdownV2',
        ]) {
        throw new \RuntimeException('Parse mode must be sent as a form parameter when provided.');
    }

    $longTitle = 'Title 🚀';
    $longUrl = 'https://example.test/notification';
    $telegram->sendNotification(
        new TestNotification($longTitle, str_repeat('🚀', 5000), $longUrl),
        ['botToken' => 'test-token', 'botChatID' => 'test-chat'],
        []
    );
    $limitedMessage = HttpClient::$lastRequest[1]['form_params']['text'];
    preg_match_all('/./us', $limitedMessage, $unicodeCharacters);
    if (count($unicodeCharacters[0]) > 4096) {
        throw new \RuntimeException('Notification messages must not exceed Telegram\'s Unicode character limit.');
    }
    if (strpos($limitedMessage, $longTitle . "\n\n") !== 0
        || substr($limitedMessage, -strlen("\n\nOpen » " . $longUrl)) !== "\n\nOpen » " . $longUrl
    ) {
        throw new \RuntimeException('Long notification messages must retain their title and URL.');
    }

    $method->invoke($telegram, 'test-token', 'test-chat', str_repeat('a', 4094) . '&amp;', 'HTML');
    $limitedMessage = HttpClient::$lastRequest[1]['form_params']['text'];
    if (substr($limitedMessage, -strlen('…')) !== '…' || strpos($limitedMessage, '&') !== false) {
        throw new \RuntimeException('Message truncation must not split an HTML entity.');
    }

    $method->invoke($telegram, 'test-token', 'test-chat', str_repeat('a', 4095) . '\\*', 'MarkdownV2');
    $limitedMessage = HttpClient::$lastRequest[1]['form_params']['text'];
    if (substr($limitedMessage, -strlen('…')) !== '…'
        || substr($limitedMessage, -strlen('…') - 1, 1) === '\\'
    ) {
        throw new \RuntimeException('Message truncation must not split a Markdown escape sequence.');
    }

    HttpClient::$requests = [];
    HttpClient::$responseQueue = [
        new Response(429, '{"ok":false,"error_code":429,"parameters":{"retry_after":0}}'),
        new Response(),
    ];
    $telegram->testConnection(['botToken' => 'test-token', 'botChatID' => 'test-chat']);
    if (count(HttpClient::$requests) !== 2) {
        throw new \RuntimeException('HTTP 429 with retry_after must be retried once.');
    }

    HttpClient::$requests = [];
    HttpClient::$responseQueue = [
        new Response(503, '{"ok":false,"error_code":503}'),
        new Response(),
    ];
    $telegram->testConnection(['botToken' => 'test-token', 'botChatID' => 'test-chat']);
    if (count(HttpClient::$requests) !== 2) {
        throw new \RuntimeException('HTTP 5xx must be retried once.');
    }

    HttpClient::$requests = [];
    HttpClient::$responseQueue = [
        new TransferException('connection failed'),
        new Response(),
    ];
    $telegram->testConnection(['botToken' => 'test-token', 'botChatID' => 'test-chat']);
    if (count(HttpClient::$requests) !== 2) {
        throw new \RuntimeException('Connection transfer failures must be retried once.');
    }

    HttpClient::$requests = [];
    HttpClient::$responseQueue = [new Response(400, '{"ok":false,"error_code":400,"description":"Bad Request"}')];
    try {
        $telegram->testConnection(['botToken' => 'test-token', 'botChatID' => 'test-chat']);
        throw new \RuntimeException('HTTP 4xx must fail.');
    } catch (\WHMCS\Exception $exception) {
        if (count(HttpClient::$requests) !== 1) {
            throw new \RuntimeException('HTTP 4xx must not be retried.');
        }
    }

    echo "Telegram message content checks passed.\n";
}
