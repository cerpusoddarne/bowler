<?php

namespace Vinelab\Bowler;

use Vinelab\Bowler\Traits\AdminTrait;
use Vinelab\Bowler\Traits\ConsumerTagTrait;
use Vinelab\Bowler\Traits\DeadLetteringTrait;
use Vinelab\Bowler\Traits\CompileParametersTrait;
use Vinelab\Bowler\Exceptions\Handler as BowlerExceptionHandler;

/**
 * Bowler Consumer.
 *
 * @author Ali Issa <ali@vinelab.com>
 * @author Kinane Domloje <kinane@vinelab.com>
 */
class Consumer
{
    use AdminTrait;
    use ConsumerTagTrait;
    use DeadLetteringTrait;
    use CompileParametersTrait;

    /**
     * The main class of the package where we define the channel and the connection.
     *
     * @var Vinelab\Bowler\Connection
     */
    private $connection;

    /**
     * The name of the queue bound to the exchange where the producer sends its messages.
     *
     * @var string
     */
    private $queueName;

    /**
     * The name of the exchange where the producer sends its messages to.
     *
     * @var string
     */
    private $exchangeName;

    /**
     * The binding keys used by the exchange to route messages to bounded queues.
     *
     * @var string
     */
    private $bindingKeys;

    /**
     * type of exchange:
     * fanout: routes messages to all of the queues that are bound to it and the routing key is ignored.
     *
     * direct: delivers messages to queues based on the message routing key. A direct exchange is ideal for the unicast routing of messages (although they can be used for multicast routing as well)
     *
     * default: a direct exchange with no name (empty string) pre-declared by the broker. It has one special property that makes it very useful for simple applications: every queue that is created is automatically bound to it with a routing key which is the same as the queue name
     *
     * topic: route messages to one or many queues based on matching between a message routing key and the pattern that was used to bind a queue to an exchange. The topic exchange type is often used to implement various publish/subscribe pattern variations. Topic exchanges are commonly used for the multicast routing of messages
     *
     * @var string
     */
    private $exchangeType;

    /**
     * If set, the server will reply with Declare-Ok if the exchange already exists with the same name, and raise an error if not. The client can use this to check whether an exchange exists without modifying the server state.
     *
     * @var bool
     */
    private $passive;

    /**
     * If set when creating a new exchange, the exchange will be marked as durable. Durable exchanges remain active when a server restarts. Non-durable exchanges (transient exchanges) are purged if/when a server restarts.
     *
     * @var bool
     */
    private $durable;

    /**
     * If set, the exchange is deleted when all queues have finished using it.
     *
     * @var bool
     */
    private $autoDelete;

    /**
     * The arguments that should be added to the `queue_declare` statement for dead lettering.
     *
     * @var array
     */
    private $arguments = [];

    /**
     * @param Vinelab\Bowler\Connection $connection
     * @param string                    $queueName
     * @param string                    $exchangeName
     * @param string                    $exchangeType
     * @param array                     $bindingKeys
     * @param bool                      $passive
     * @param bool                      $durable
     * @param bool                      $autoDelete
     */
    public function __construct(Connection $connection, $queueName, $exchangeName, $exchangeType = 'fanout', $bindingKeys = [], $passive = false, $durable = true, $autoDelete = false)
    {
        $this->connection = $connection;
        $this->queueName = $queueName;
        $this->exchangeName = $exchangeName;
        $this->exchangeType = $exchangeType;
        $this->bindingKeys = $bindingKeys;
        $this->passive = $passive;
        $this->durable = $durable;
        $this->autoDelete = $autoDelete;
    }

    /**
     * consume a message from a specified exchange.
     *
     * @param string                            $handlerClass
     * @param Vinelab\Bowler\Exceptions\Handler $exceptionHandler
     */
    public function listenToQueue($handlerClass, BowlerExceptionHandler $exceptionHandler)
    {
        // Get connection channel
        $channel = $this->connection->getChannel();

        try {
            $channel->exchange_declare($this->exchangeName, $this->exchangeType, $this->passive, $this->durable, $this->autoDelete);
            $channel->queue_declare($this->queueName, $this->passive, $this->durable, false, $this->autoDelete, false, $this->arguments);
        } catch (\Exception $e) {
            $exceptionHandler->handleServerException($e, $this->compileParameters(), $this->arguments);
        }

        if (!empty($this->bindingKeys)) {
            foreach ($this->bindingKeys as $bindingKey) {
                $channel->queue_bind($this->queueName, $this->exchangeName, $bindingKey);
            }
        } else {
            $channel->queue_bind($this->queueName, $this->exchangeName);
        }

        $callback = function ($message) use ($handlerClass, $exceptionHandler) {
            // Instantiate Handler
            $queueHandler = app($handlerClass);

            $broker = new MessageBroker($message);

            try {
                $queueHandler->handle($message);
                $broker->ackMessage();
            } catch (\Exception $e) {
                $exceptionHandler->reportError($e, $message);

                if (method_exists($queueHandler, 'handleError')) {
                    $queueHandler->handleError($e, $broker);
                }
            }
        };

        $channel->basic_qos(null, 1, null);
        $tag = $channel->basic_consume($this->queueName, '', false, false, false, false, $callback);

        $this->writeConsumerTag($tag);

        echo ' [*] Listening to Queue: ', $this->queueName, ' To exit press CTRL+C', "\n";

        while (count($channel->callbacks)) {
            $channel->wait();
        }
    }
}
