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

        public function post($url, array $options)
        {
            self::$lastRequest = [$url, $options];

            return new Response();
        }
    }

    class Response
    {
        public function getStatusCode()
        {
            return 200;
        }

        public function getBody()
        {
            return '{"ok":true}';
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
    use WHMCS\Http\Client\HttpClient;
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

    echo "Telegram message content checks passed.\n";
}
