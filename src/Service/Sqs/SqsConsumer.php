<?php declare(strict_types=1);

namespace Bref\Symfony\Messenger\Service\Sqs;

use Bref\Context\Context;
use Bref\Event\Sqs\SqsEvent;
use Bref\Event\Sqs\SqsHandler;
use Bref\Symfony\Messenger\Service\BusDriver;
use Symfony\Component\Messenger\Bridge\AmazonSqs\Transport\AmazonSqsReceivedStamp;
use Symfony\Component\Messenger\Bridge\AmazonSqs\Transport\AmazonSqsXrayTraceHeaderStamp;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;

final class SqsConsumer extends SqsHandler
{
    private const MESSAGE_ATTRIBUTE_NAME = 'X-Symfony-Messenger';

    /** @var MessageBusInterface */
    private $bus;
    /** @var SerializerInterface */
    protected $serializer;
    /** @var string */
    private $transportName;
    /** @var BusDriver */
    private $busDriver;

    public function __construct(
        BusDriver $busDriver,
        MessageBusInterface $bus,
        SerializerInterface $serializer,
        string $transportName
    ) {
        $this->busDriver = $busDriver;
        $this->bus = $bus;
        $this->serializer = $serializer;
        $this->transportName = $transportName;
    }

    public function handleSqs(SqsEvent $event, Context $context): void
    {
        foreach ($event->getRecords() as $record) {
            $headers = [];
            $attributes = $record->getMessageAttributes();

            if (isset($attributes[self::MESSAGE_ATTRIBUTE_NAME]) && $attributes[self::MESSAGE_ATTRIBUTE_NAME]['dataType'] === 'String') {
                $headers = json_decode($attributes[self::MESSAGE_ATTRIBUTE_NAME]['stringValue'], true);
                unset($attributes[self::MESSAGE_ATTRIBUTE_NAME]);
            }
            foreach ($attributes as $name => $attribute) {
                if ($attribute['dataType'] !== 'String') {
                    continue;
                }
                $headers[$name] = $attribute['stringValue'];
            }

            $envelope = $this->serializer->decode(['body' => $record->getBody(), 'headers' => $headers]);

            $stamps = [new AmazonSqsReceivedStamp($record->getMessageId())];

            if ('' !== $context->getTraceId()) {
                $stamps[] = new AmazonSqsXrayTraceHeaderStamp($context->getTraceId());
            }
            $this->busDriver->putEnvelopeOnBus($this->bus, $envelope->with(...$stamps), $this->transportName);
        }
    }
}
