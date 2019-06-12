<?php

namespace DsWebCrawlerBundle\EventSubscriber;

use DsWebCrawlerBundle\DsWebCrawlerBundle;
use DsWebCrawlerBundle\DsWebCrawlerEvents;
use DynamicSearchBundle\Context\ContextDataInterface;
use DynamicSearchBundle\Logger\LoggerInterface;
use Symfony\Component\EventDispatcher\Event;
use Symfony\Component\EventDispatcher\GenericEvent;
use VDB\Spider\Event\SpiderEvents;

class LogEventSubscriber implements EventSubscriberInterface
{
    protected $startedTime;

    /**
     * @var int
     */
    protected $persisted = 0;

    /**
     * @var int
     */
    protected $queued = 0;

    /**
     * @var int
     */
    protected $filtered = 0;

    /**
     * @var int
     */
    protected $failed = 0;

    /**
     * @var ContextDataInterface
     */
    protected $contextData;

    /**
     * @var LoggerInterface
     */
    protected $logger;

    /**
     * @param ContextDataInterface $contextData
     */
    public function setContextData(ContextDataInterface $contextData)
    {
        $this->contextData = $contextData;
    }

    /**
     * @param LoggerInterface $logger
     */
    public function setLogger(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }

    /**
     * @return array
     */
    public static function getSubscribedEvents()
    {
        return [
            SpiderEvents::SPIDER_CRAWL_FILTER_POSTFETCH   => 'logFiltered',
            SpiderEvents::SPIDER_CRAWL_FILTER_PREFETCH    => 'logFiltered',
            SpiderEvents::SPIDER_CRAWL_POST_ENQUEUE       => 'logQueued',
            SpiderEvents::SPIDER_CRAWL_RESOURCE_PERSISTED => 'logPersisted',
            SpiderEvents::SPIDER_CRAWL_ERROR_REQUEST      => 'logFailed',

            SpiderEvents::SPIDER_CRAWL_POST_REQUEST => 'logCrawled',
            SpiderEvents::SPIDER_CRAWL_USER_STOPPED => 'logStoppedBySignal',

            DsWebCrawlerEvents::DS_WEB_CRAWLER_START       => 'logStarted',
            DsWebCrawlerEvents::DS_WEB_CRAWLER_FINISH      => 'logFinished',
            DsWebCrawlerEvents::DS_WEB_CRAWLER_INTERRUPTED => 'logStopped'
        ];
    }

    /**
     * @param GenericEvent $event
     */
    public function logStarted(GenericEvent $event)
    {
        $this->startedTime = microtime(true);
    }

    /**
     * @param GenericEvent $event
     */
    public function logFinished(GenericEvent $event)
    {
        $totalTime = microtime(true) - $this->startedTime;
        $totalTime = number_format((float) $totalTime, 3, '.', '');
        $minutes = str_pad(floor($totalTime / 60), 2, '0', STR_PAD_LEFT);
        $seconds = str_pad($totalTime % 60, 2, '0', STR_PAD_LEFT);
        $peakMem = round(memory_get_peak_usage(true) / 1024 / 1024, 2);

        $this->logEvent('finished', $event, 'debug', 'enqueued links: ' . $this->queued);
        $this->logEvent('finished', $event, 'debug', 'skipped links: ' . $this->filtered);
        $this->logEvent('finished', $event, 'debug', 'failed links: ' . $this->failed);
        $this->logEvent('finished', $event, 'debug', 'persisted links: ' . $this->persisted);
        $this->logEvent('finished', $event, 'debug', 'memory peak usage: ' . $peakMem . 'MB');
        $this->logEvent('finished', $event, 'debug', 'total time: ' . $minutes . ':' . $seconds);
    }

    /**
     * @param GenericEvent $event
     */
    public function logQueued(GenericEvent $event)
    {
        $this->queued++;

        $this->logEvent('queued', $event);
    }

    /**
     * @param GenericEvent $event
     */
    public function logPersisted(GenericEvent $event)
    {
        $this->persisted++;

        $this->logEvent('persisted', $event);
    }

    /**
     * @param GenericEvent $event
     */
    public function logFiltered(GenericEvent $event)
    {
        $this->queued++;

        $filterType = $event->hasArgument('filterType') ? $event->getArgument('filterType') . '.' : '';
        $name = $filterType . 'filtered';
        $this->logEvent($name, $event);
    }

    /**
     * @param GenericEvent $event
     */
    public function logFailed(GenericEvent $event)
    {
        $this->failed++;

        $message = preg_replace('/\s+/S', ' ', $event->getArgument('message'));
        $this->logEvent('failed', $event, 'critical', $message);
    }

    /**
     * @param Event $event
     */
    public function logStoppedBySignal(Event $event)
    {
        $logEvent = new GenericEvent($this, ['errorMessage' => 'crawling canceled (lost signal)']);
        $this->logEvent('stopped', $logEvent, 'debug', $logEvent->getArgument('errorMessage'));
    }

    /**
     * @param GenericEvent $event
     */
    public function logStopped(GenericEvent $event)
    {
        $this->logEvent('stopped', $event, 'debug', $event->getArgument('errorMessage'));
    }

    /**
     * @param GenericEvent $event
     */
    public function logCrawled(GenericEvent $event)
    {
        $this->logEvent('uri.crawled', $event, 'debug');
    }

    /**
     * @param              $name
     * @param GenericEvent $event
     * @param              $debugLevel
     * @param string       $additionalMessage
     */
    protected function logEvent($name, GenericEvent $event, $debugLevel = 'debug', $additionalMessage = '')
    {
        $triggerLog = in_array($name, [
            'uri.crawled',
            'uri.match.invalid.filtered',
            'uri.match.forbidden.filtered',
            'filtered',
            'failed',
            'stopped',
            'started',
            'finished',
        ]);

        if ($triggerLog) {

            $prefix = '[spider.' . $name . '] ';

            $message = $prefix;
            if (!empty($additionalMessage)) {
                $message .= $additionalMessage . ' ';
            }

            $message .= $event->hasArgument('uri') ? $event->getArgument('uri')->toString() : '[uri not available]';

            $this->logger->log($debugLevel, $message, DsWebCrawlerBundle::PROVIDER_NAME, $this->contextData->getName());
        }
    }
}