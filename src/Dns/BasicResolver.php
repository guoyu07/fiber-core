<?php
namespace Fiber\Dns;

use LibDNS\Messages\MessageFactory;
use LibDNS\Messages\MessageTypes;
use LibDNS\Records\QuestionFactory;
use LibDNS\Records\ResourceQTypes;
use LibDNS\Encoder\EncoderFactory;
use LibDNS\Decoder\DecoderFactory;
use LibDNS\Encoder\Encoder;
use LibDNS\Decoder\Decoder;

class BasicResolver
{
    private static $default_instance;
    private $nameservers;
    private $attempts = 3;
    private $timeout_ms = 100;

    /**
     * @var QuestionFactory
     */
    private $question_factory;

    /**
     * @var MessageFactory
     */
    private $message_factory;

    /**
     * @var Encoder
     */
    private $encoder;

    /**
     * @var Decoder
     */
    private $decoder;

    private function __construct(array $nameservers, int $attempts, int $timeout_ms)
    {
        foreach ($nameservers as $ip) {
            if (\inet_pton($ip)) {
                $this->nameservers[] = $ip;
            }
        }

        if (!$nameservers) {
            throw new \InvalidArgumentException('invalid nameservers');
        }

        if ($attempts > 0) {
            $this->attempts = $attempts;
        }

        if ($timeout_ms > 0) {
            $this->timeout_ms = $timeout_ms;
        }

        $this->question_factory = new QuestionFactory;
        $this->message_factory = new MessageFactory;
        $this->encoder = (new EncoderFactory)->create();
        $this->decoder = (new DecoderFactory)->create();
    }

    public function dig(string $name, $type = ResourceQTypes::A)
    {
        if (@\inet_pton($name)) {
            return [[
                'ip' => $name,
            ]];
        }

        $question = $this->question_factory->create(ResourceQTypes::A);
        $question->setName($name);

        $request = $this->message_factory->create(MessageTypes::QUERY);
        $request->getQuestionRecords()->add($question);
        $request->isRecursionDesired(true);

        $attempts = $this->attempts;
        $nameservers = $this->nameservers;

        for ($i = 0; $i < $attempts; $i++) {
            $socket = socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
            socket_set_nonblock($socket);

            $_i = $i % \count($nameservers);
            socket_connect($socket, $nameservers[$_i], 53);

            \f\write($socket, $this->encoder->encode($request));
            $response = \f\read0($socket, 512, 100);

            try {
                $response = $this->decoder->decode($response);
            } catch (\InvalidArgumentException $e) {
                continue;
            } catch (\UnexpectedValueException $e) {
                continue;
            }

            $ips = [];
            /** @var \LibDNS\Records\Resource $record */
            foreach ($response->getAnswerRecords() as $record) {
                if ($record->getType() !== $type) {
                    continue;
                }

                $ips[] = [
                    'ip' => (string)$record->getData(),
                ];
            }
        }

        return $ips;
    }

    public static function init() : self
    {
        if (self::$default_instance) {
            return self::$default_instance;
        }

        $content = file_get_contents('/etc/resolv.conf');
        $lines = \explode("\n", $content);
        $config = [
            'nameservers' => [],
            'timeout' => 0,
            'attempts' => 0,
        ];

        foreach ($lines as $line) {
            $line = \preg_split('#\s+#', $line, 2);

            if (!isset($line[1])) {
                continue;
            }

            list($type, $value) = $line;

            if ($type === 'nameserver') {
                $value = \trim($value);
                if (\inet_pton($value)) {
                    $config['nameservers'][] = $value;
                }
            } elseif ($type === 'options') {
                $optline = \preg_split('#\s+#', $value, 2);

                if (!isset($optline[1])) {
                    continue;
                }

                list($option, $value) = $optline;

                switch ($option) {
                case "timeout":
                    $config['timeout'] = (int) $value;
                    break;
                case "attempts":
                    $config['attempts'] = (int) $value;
                    break;
                }

            }
        }

        $default_instance = new self($config['nameservers'], $config['attempts'], $config['timeout'] * 1000);
        self::$default_instance = $default_instance;

        return $default_instance;
    }
}
