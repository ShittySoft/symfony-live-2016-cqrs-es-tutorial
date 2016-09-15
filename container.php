<?php

declare(strict_types=1);

namespace Building\App;

use Bernard\Driver\FlatFileDriver;
use Bernard\Producer;
use Bernard\Queue;
use Bernard\QueueFactory;
use Bernard\QueueFactory\PersistentFactory;
use Building\Domain\Aggregate\Building;
use Building\Domain\Authorization\AuthorizedUsersInterface;
use Building\Domain\Command;
use Building\Domain\DomainEvent\UserCheckedMultipleTimesIntoBuilding;
use Building\Domain\DomainEvent\UserWasCheckedIntoBuilding;
use Building\Domain\DomainEvent\UserWasCheckedOutOfBuilding;
use Building\Domain\ProcessManager\NotifyAdministratorWhenUserCheckedInMultipleTimes;
use Building\Domain\Repository\BuildingRepositoryInterface;
use Building\Infrastructure\Repository\BuildingRepository;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Driver\PDOSqlite\Driver;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Schema\SchemaException;
use Interop\Container\ContainerInterface;
use Prooph\Common\Event\ActionEvent;
use Prooph\Common\Event\ActionEventEmitter;
use Prooph\Common\Event\ActionEventListenerAggregate;
use Prooph\Common\Event\ProophActionEventEmitter;
use Prooph\Common\Messaging\FQCNMessageFactory;
use Prooph\Common\Messaging\NoOpMessageConverter;
use Prooph\EventSourcing\EventStoreIntegration\AggregateTranslator;
use Prooph\EventStore\Adapter\Doctrine\DoctrineEventStoreAdapter;
use Prooph\EventStore\Adapter\Doctrine\Schema\EventStoreSchema;
use Prooph\EventStore\Adapter\PayloadSerializer\JsonPayloadSerializer;
use Prooph\EventStore\Aggregate\AggregateRepository;
use Prooph\EventStore\Aggregate\AggregateType;
use Prooph\EventStore\EventStore;
use Prooph\EventStoreBusBridge\EventPublisher;
use Prooph\EventStoreBusBridge\TransactionManager;
use Prooph\ServiceBus\Async\MessageProducer;
use Prooph\ServiceBus\CommandBus;
use Prooph\ServiceBus\EventBus;
use Prooph\ServiceBus\Message\Bernard\BernardMessageProducer;
use Prooph\ServiceBus\Message\Bernard\BernardSerializer;
use Prooph\ServiceBus\MessageBus;
use Prooph\ServiceBus\Plugin\ServiceLocatorPlugin;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Zend\ServiceManager\ServiceManager;

require_once __DIR__ . '/vendor/autoload.php';

return new ServiceManager([
    'factories' => [
        Connection::class => function () {
            $connection = DriverManager::getConnection([
                'driverClass' => Driver::class,
                'path'        => __DIR__ . '/data/db.sqlite3',
            ]);

            try {
                $schema = $connection->getSchemaManager()->createSchema();

                EventStoreSchema::createSingleStream($schema, 'event_stream', true);

                foreach ($schema->toSql($connection->getDatabasePlatform()) as $sql) {
                    $connection->exec($sql);
                }
            } catch (SchemaException $ignored) {
            }

            return $connection;
        },

        EventStore::class                  => function (ContainerInterface $container) {
            $eventBus   = new EventBus();
            $eventStore = new EventStore(
                new DoctrineEventStoreAdapter(
                    $container->get(Connection::class),
                    new FQCNMessageFactory(),
                    new NoOpMessageConverter(),
                    new JsonPayloadSerializer()
                ),
                new ProophActionEventEmitter()
            );

            $eventBus->utilize(new class ($container, $container) implements ActionEventListenerAggregate
            {
                /**
                 * @var ContainerInterface
                 */
                private $eventHandlers;

                /**
                 * @var ContainerInterface
                 */
                private $projectors;

                public function __construct(
                    ContainerInterface $eventHandlers,
                    ContainerInterface $projectors
                ) {
                    $this->eventHandlers = $eventHandlers;
                    $this->projectors    = $projectors;
                }

                public function attach(ActionEventEmitter $dispatcher)
                {
                    $dispatcher->attachListener(MessageBus::EVENT_ROUTE, [$this, 'onRoute']);
                }

                public function detach(ActionEventEmitter $dispatcher)
                {
                    throw new \BadMethodCallException('Not implemented');
                }

                public function onRoute(ActionEvent $actionEvent)
                {
                    $messageName = (string) $actionEvent->getParam(MessageBus::EVENT_PARAM_MESSAGE_NAME);

                    $handlers = [];

                    $listeners  = $messageName . '-listeners';
                    $projectors = $messageName . '-projectors';

                    if ($this->projectors->has($projectors)) {
                        $handlers = array_merge($handlers, $this->eventHandlers->get($projectors));
                    }

                    if ($this->eventHandlers->has($listeners)) {
                        $handlers = array_merge($handlers, $this->eventHandlers->get($listeners));
                    }

                    if ($handlers) {
                        $actionEvent->setParam(EventBus::EVENT_PARAM_EVENT_LISTENERS, $handlers);
                    }
                }
            });

            (new EventPublisher($eventBus))->setUp($eventStore);

            return $eventStore;
        },

        'immediate-command-bus'                  => function (ContainerInterface $container) : CommandBus {
            $commandBus = new CommandBus();

            $commandBus->utilize(new ServiceLocatorPlugin($container));
            $commandBus->utilize(new class implements ActionEventListenerAggregate {
                public function attach(ActionEventEmitter $dispatcher)
                {
                    $dispatcher->attachListener(MessageBus::EVENT_ROUTE, [$this, 'onRoute']);
                }

                public function detach(ActionEventEmitter $dispatcher)
                {
                    throw new \BadMethodCallException('Not implemented');
                }

                public function onRoute(ActionEvent $actionEvent)
                {
                    $actionEvent->setParam(
                        MessageBus::EVENT_PARAM_MESSAGE_HANDLER,
                        (string) $actionEvent->getParam(MessageBus::EVENT_PARAM_MESSAGE_NAME)
                    );
                }
            });

            $transactionManager = new TransactionManager();
            $transactionManager->setUp($container->get(EventStore::class));

            $commandBus->utilize($transactionManager);

            return $commandBus;
        },

        CommandBus::class => function (ContainerInterface $container) : CommandBus {
            return new class ($container->get(MessageProducer::class)) extends CommandBus
            {
                /**
                 * @var MessageProducer
                 */
                private $messageProducer;

                public function __construct(MessageProducer $messageProducer)
                {
                    $this->messageProducer = $messageProducer;
                }

                public function dispatch($command)
                {
                    $this->messageProducer->__invoke($command);
                }
            };
        },

        // ignore this - this is async stuff
        // we'll get to it later

        QueueFactory::class => function () : QueueFactory {
            return new PersistentFactory(
                new FlatFileDriver(__DIR__ . '/data/bernard'),
                new BernardSerializer(new FQCNMessageFactory(), new NoOpMessageConverter())
            );
        },

        Queue::class => function (ContainerInterface $container) : Queue {
            return $container->get(QueueFactory::class)->create('commands');
        },

        MessageProducer::class => function (ContainerInterface $container) : MessageProducer {
            return new BernardMessageProducer(
                new Producer($container->get(QueueFactory::class),new EventDispatcher()),
                'commands'
            );
        },

        // Command -> CommandHandlerFactory
        // this is where most of the work will be done (by you!)
        Command\RegisterNewBuilding::class => function (ContainerInterface $container) : callable {
            $buildings = $container->get(BuildingRepositoryInterface::class);

            return function (Command\RegisterNewBuilding $command) use ($buildings) {
                $buildings->add(Building::new($command->name()));
            };
        },
        Command\CheckUserIntoBuilding::class => function (ContainerInterface $container) : callable {
            $buildings = $container->get(BuildingRepositoryInterface::class);

            return function (Command\CheckUserIntoBuilding $command) use ($buildings) {
                $building = $buildings->get($command->building());

                $building->checkInUser(
                    new class implements AuthorizedUsersInterface {
                        public function has(string $username) : bool {
                            return in_array($username, ['fritz', 'franz', 'otto'], true);
                        }
                    },
                    $command->username()
                );

                $buildings->add($building);
            };
        },
        Command\CheckUserOutOfBuilding::class => function (ContainerInterface $container) : callable {
            $buildings = $container->get(BuildingRepositoryInterface::class);

            return function (Command\CheckUserOutOfBuilding $command) use ($buildings) {
                $building = $buildings->get($command->building());

                $building->checkOutUser($command->username());

                $buildings->add($building);
            };
        },
        Command\NotifyAdministratorOfMultipleCheckInAnomaly::class => function (ContainerInterface $container) : callable {
            return function (Command\NotifyAdministratorOfMultipleCheckInAnomaly $command) {
                error_log(sprintf(
                    'User "%s" is cheating the system at building "%s"',
                    $command->username(),
                    $command->building()->toString()
                ));
            };
        },
        'refresh-current-users-in-building-json' => function (ContainerInterface $container) : callable {
            $connection = $container->get(Connection::class);

            return function () use ($connection) {
                $relevantEvents = $connection
                    ->executeQuery(
                        'SELECT aggregate_id, payload, event_name FROM event_stream WHERE event_name IN (:eventNames) ORDER BY aggregate_id ASC, version ASC',
                        ['eventNames' => [UserWasCheckedIntoBuilding::class, UserWasCheckedOutOfBuilding::class]],
                        ['eventNames' => Connection::PARAM_STR_ARRAY]
                    );

                $lastAggregate = null;

                while ($row = $relevantEvents->fetch(\PDO::FETCH_ASSOC)) {
                    $path = __DIR__ . '/public/proper-' . $row['aggregate_id'] . '.json';

                    if ($lastAggregate !== $row['aggregate_id']) {
                        @unlink($path);
                        $lastAggregate = $row['aggregate_id'];
                    }

                    $usernames = [];

                    if (file_exists($path)) {
                        $usernames = array_map('strval', json_decode(file_get_contents($path), true));
                    }

                    $username = json_decode($row['payload'], true)['username'];

                    $allUsernames = array_values(array_unique(array_merge($usernames, [$username])));

                    if ($row['event_name'] === UserWasCheckedOutOfBuilding::class) {
                        $usernamesIndexedByUsername = array_flip($allUsernames);

                        unset($usernamesIndexedByUsername[$username]);

                        $allUsernames = array_values(array_flip($usernamesIndexedByUsername));
                    }

                    file_put_contents($path, json_encode($allUsernames));
                }
            };
        },
        UserWasCheckedIntoBuilding::class . '-projectors' => function (ContainerInterface $container) : array {
            return [
                function (UserWasCheckedIntoBuilding $event) {
                    $path = __DIR__ . '/public/' . $event->aggregateId() . '.json';

                    $usernames = [];

                    if (file_exists($path)) {
                        $usernames = array_map('strval', json_decode(file_get_contents($path), true));
                    }

                    file_put_contents($path, json_encode(array_values(array_unique(array_merge($usernames, [$event->username()])))));
                },
                $container->get('refresh-current-users-in-building-json'),
            ];
        },
        UserWasCheckedOutOfBuilding::class . '-projectors' => function (ContainerInterface $container) : array {
            return [
                function (UserWasCheckedOutOfBuilding $event) {
                    $path = __DIR__ . '/public/' . $event->aggregateId() . '.json';

                    $usernames = [];

                    if (file_exists($path)) {
                        $usernames = array_map('strval', json_decode(file_get_contents($path), true));
                    }

                    $usernamesIndexedByUsername = array_flip($usernames);

                    unset($usernamesIndexedByUsername[$event->username()]);

                    file_put_contents($path, json_encode(array_values(array_flip($usernamesIndexedByUsername))));
                },
                $container->get('refresh-current-users-in-building-json'),
            ];
        },
        UserCheckedMultipleTimesIntoBuilding::class . '-listeners' => function (ContainerInterface $container) : array {
            return [
                new NotifyAdministratorWhenUserCheckedInMultipleTimes([$container->get(CommandBus::class), 'dispatch']),
            ];
        },
        BuildingRepositoryInterface::class => function (ContainerInterface $container) : BuildingRepositoryInterface {
            return new BuildingRepository(
                new AggregateRepository(
                    $container->get(EventStore::class),
                    AggregateType::fromAggregateRootClass(Building::class),
                    new AggregateTranslator()
                )
            );
        },
    ],
]);