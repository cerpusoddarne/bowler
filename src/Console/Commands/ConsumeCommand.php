<?php

namespace Vinelab\Bowler\Console\Commands;

use Vinelab\Bowler\Consumer;
use Vinelab\Bowler\Connection;
use Illuminate\Console\Command;
use Vinelab\Bowler\RegisterQueues;
use Vinelab\Bowler\Facades\Registrator;
use Vinelab\Bowler\Contracts\BowlerExceptionHandler as ExceptionHandler;

/**
 * @author Ali Issa <ali@vinelab.com>
 * @author Kinane Domloje <kinane@vinelab.com>
 */
class ConsumeCommand extends Command
{
    protected $registerQueues;

    public function __construct(RegisterQueues $registerQueues)
    {
        parent::__construct();

        $this->registerQueues = $registerQueues;
    }

    /**
     * The console command name.
     *
     * @var string
     */
    protected $signature = 'bowler:consume
                            {queueName : The queue NAME}
                            {--N|exchangeName= : The exchange NAME. Defaults to queueName}
                            {--T|exchangeType=fanout : The exchange TYPE. Supported exchanges: fanout, direct, topic. Defaults to fanout}
                            {--K|bindingKeys=* : The consumer\'s BINDING KEYS (array)}
                            {--p|passive=0 : If set, the server will reply with Declare-Ok if the exchange and queue already exists with the same name, and raise an error if not. Defaults to 0}
                            {--d|durable=1 : Mark exchange and queue as DURABLE. Defaults to 1}
                            {--D|autoDelete=0 : Set exchange and queue to AUTO DELETE when all queues and consumers, respectively have finished using it. Defaults to 0}
                            {--M|deliveryMode=2 : The message DELIVERY MODE. Non-persistent 1 or persistent 2. Defaults to 2}
                            {--deadLetterQueueName= : The dead letter queue NAME. Defaults to deadLetterExchangeName}
                            {--deadLetterExchangeName= : The dead letter exchange NAME. Defaults to deadLetterQueueName}
                            {--deadLetterExchangeType=fanout : The dead letter exchange TYPE. Supported exchanges: fanout, direct, topic. Defaults to fanout}
                            {--deadLetterRoutingKey= : The dead letter ROUTING KEY}
                            {--messageTTL= : If set, specifies how long, in milliseconds, before a message is declared dead letter}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Register a consumer to its queue';

    /**
     * Run the command.
     *
     * @return void.
     */
    public function handle()
    {
        $queueName = $this->argument('queueName');

        $exchangeName = ($name = $this->option('exchangeName')) ? $name : $queueName; // If the exchange name has not been set, use the queue name
        $exchangeType = $this->option('exchangeType');
        $bindingKeys = (array) $this->option('bindingKeys');
        $passive = (bool) $this->option('passive');
        $durable = (bool) $this->option('durable');
        $autoDelete = (bool) $this->option('autoDelete');
        $deliveryMode = (int) $this->option('deliveryMode');

        // Dead Lettering
        $deadLetterQueueName = ($dlQueueName = $this->option('deadLetterQueueName')) ? $dlQueueName : (($dlExchangeName = $this->option('deadLetterExchangeName')) ? $dlExchangeName : null);
        $deadLetterExchangeName = ($dlExchangeName = $this->option('deadLetterExchangeName')) ? $dlExchangeName : (($dlQueueName = $this->option('deadLetterQueueName')) ? $dlQueueName : null);
        $deadLetterExchangeType = $this->option('deadLetterExchangeType');
        $deadLetterRoutingKey = $this->option('deadLetterRoutingKey');
        $messageTTL = (int) $this->option('messageTTL');

        require(app_path().'/Messaging/queues.php');
        $handlers = Registrator::getHandlers();

        foreach ($handlers as $handler) {
          if ($handler->queueName == $queueName) {
            $bowlerConsumer = new Consumer(app(Connection::class), $handler->queueName, $exchangeName, $exchangeType, $bindingKeys, $passive, $durable, $autoDelete, $deliveryMode);
            if($deadLetterQueueName) {
                $bowlerConsumer->configureDeadLettering($deadLetterQueueName, $deadLetterExchangeName, $deadLetterExchangeType, $deadLetterRoutingKey, $messageTTL);
            }
            $bowlerConsumer->listenToQueue($handler->className, app(ExceptionHandler::class));
          }
        }

    }
}