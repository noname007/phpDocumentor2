<?php
/**
 * phpDocumentor
 *
 * PHP Version 5.4
 *
 * @copyright 2010-2014 Mike van Riel / Naenius (http://www.naenius.com)
 * @license   http://www.opensource.org/licenses/mit-license.php MIT
 * @link      http://phpdoc.org
 */

namespace phpDocumentor\Command\Helper;

use phpDocumentor\Command\Command;
use phpDocumentor\Configuration;
use phpDocumentor\Descriptor\FileDescriptor;
use phpDocumentor\Descriptor\Validator\Error;
use phpDocumentor\Event\Dispatcher;
use phpDocumentor\Event\LogEvent;
use phpDocumentor\Parser\Backend\Php;
use Psr\Log\LogLevel;
use Symfony\Component\Console\Helper\Helper;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\EventDispatcher\GenericEvent;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

class LoggerHelper extends Helper
{
    /** @var Dispatcher */
    private $dispatcher;

    /**
     * Initializes this helper with the event dispatcher.
     *
     * @param Dispatcher $dispatcher
     */
    public function __construct(Dispatcher $dispatcher)
    {
        $this->dispatcher = $dispatcher;
    }

    /**
     * Returns the canonical name of this helper.
     *
     * @return string The canonical name
     *
     * @api
     */
    public function getName()
    {
        return 'phpdocumentor_logger';
    }

    /**
     * Connect the logging events to the output object of Symfony Console.
     *
     * @param OutputInterface $output
     * @param Command $command
     *
     * @return void
     */
    public function connectOutputToLogging(OutputInterface $output, $command)
    {
        static $alreadyConnected = false;
        $helper = $this;

        // ignore any second or later invocations of this method
        if ($alreadyConnected) {
            return;
        }

        $eventDispatcher = $this->dispatcher;

        $eventDispatcher->addListener(
            Php::EVENT_FILE_IS_CACHED,
            function (GenericEvent $event) use ($output, $eventDispatcher) {
                $message = 'Found cached file <info>%1s</info>';
                $this->logFileWithErrors($event->getSubject(), $output, $eventDispatcher, $message);
            }
        );

        $eventDispatcher->addListener(
            Php::EVENT_ANALYZED_FILE,
            function (GenericEvent $event) use ($output, $eventDispatcher) {
                $message = 'Parsed modified file <info>%1s</info>';
                $this->logFileWithErrors($event->getSubject(), $output, $eventDispatcher, $message);
            }
        );

        $eventDispatcher->addListener(
            'system.log',
            function (LogEvent $event) use ($command, $helper, $output) {
                $helper->logEvent($output, $event, $command);
            }
        );

        $alreadyConnected = true;
    }

    /**
     * Log all errors discovered after parsing a file to the listening loggers.
     *
     * @param FileDescriptor           $fileDescriptor
     * @param OutputInterface          $output
     * @param EventDispatcherInterface $eventDispatcher
     * @param string                   $message
     *
     * @return void
     */
    private function logFileWithErrors(
        FileDescriptor $fileDescriptor,
        OutputInterface $output,
        EventDispatcherInterface $eventDispatcher,
        $message
    ) {
        $output->writeln(sprintf($message, $fileDescriptor->getPath()));

        /** @var Error $error */
        foreach ($fileDescriptor->getAllErrors() as $error) {
            $event = LogEvent::createInstance($this)
                ->setContext($error->getContext())
                ->setMessage($error->getCode())
                ->setPriority($error->getSeverity());

            $eventDispatcher->dispatch('system.log', $event);
        }
    }

    /**
     * Logs an event with the output.
     *
     * This method will also colorize the message based on priority and withhold
     * certain logging in case of verbosity or not.
     *
     * @param OutputInterface $output
     * @param LogEvent        $event
     * @param Command         $command
     *
     * @return void
     */
    public function logEvent(OutputInterface $output, LogEvent $event, Command $command)
    {
        $numericErrors = array(
            LogLevel::DEBUG     => 0,
            LogLevel::NOTICE    => 1,
            LogLevel::INFO      => 2,
            LogLevel::WARNING   => 3,
            LogLevel::ERROR     => 4,
            LogLevel::ALERT     => 5,
            LogLevel::CRITICAL  => 6,
            LogLevel::EMERGENCY => 7,
        );

        $threshold = LogLevel::ERROR;
        if ($output->getVerbosity() === OutputInterface::VERBOSITY_DEBUG) {
            $threshold = LogLevel::DEBUG;
        }

        if ($numericErrors[$event->getPriority()] >= $numericErrors[$threshold]) {
            $message = vsprintf($event->getMessage(), $event->getContext());

            switch ($event->getPriority()) {
                case LogLevel::WARNING:
                    $message = '<comment>' . $message . '</comment>';
                    break;
                case LogLevel::EMERGENCY:
                case LogLevel::ALERT:
                case LogLevel::CRITICAL:
                case LogLevel::ERROR:
                    $message = '<error>' . $message . '</error>';
                    break;
            }
            $output->writeln('  ' . $message);
        }
    }
}
