<?php

namespace Algolia\AlgoliaSearchSymfonyDoctrineBundle\Tests;

use Doctrine\ORM\Tools\Setup as DoctrineSetup;
use Doctrine\ORM\EntityManager;
use Doctrine\Common\EventManager;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\ORM\Mapping\Driver\AnnotationDriver;
use Doctrine\Common\Annotations\AnnotationReader;
use Doctrine\DBAL\DriverManager;

// Use lightweight test doubles that can inspect internal state
use Algolia\AlgoliaSearchSymfonyDoctrineBundle\Tests\EventListener\AlgoliaSearchDoctrineEventSubscriber;
use Algolia\AlgoliaSearchSymfonyDoctrineBundle\Tests\Indexer\Indexer;

class BaseTest extends \PHPUnit_Framework_TestCase
{
    protected $backupGlobalsBlacklist = ['kernel'];

    protected static $em = null;
    protected static $indexer = null;

    /**
     * When set to true, all tests will be ran locally,
     * i.e. skipping the part where the actual communication with Algolia
     * is done.
     * This is used to test the Doctrine/Symfony side of things in isolation.
     */
    protected static $isolateFromAlgolia = true;

    protected static function getNeededEntities()
    {
        $entities = array();
        $namespace = 'Algolia\AlgoliaSearchSymfonyDoctrineBundle\Tests\\';
        $base = 'Entity';
        foreach (scandir(__DIR__.DIRECTORY_SEPARATOR.$base) as $entry) {
            if ($entry === 'BaseTestAwareEntity.php') {
                continue;
            }

            if (preg_match('/\.php$/', $entry)) {
                $entities[] = $namespace.$base.'\\'.basename($entry, '.php');
            }
        }
        return $entities;
    }

    protected static function setupDatabase()
    {
        global $kernel;
        $conn = $kernel->getContainer()->get('database_connection');
        $dbname = $kernel->getContainer()->getParameter('database_name');
        $em = $kernel->getContainer()->get('doctrine.orm.entity_manager');
        $sm = $conn->getSchemaManager();

        $schema = array_map(function ($class) use ($em) {
            return $em->getClassMetadata($class);
        }, static::getNeededEntities());

        $schemaTool = new SchemaTool($em);
        $schemaTool->dropSchema($schema);
        $schemaTool->createSchema($schema);
    }

    public static function setupBeforeClass()
    {
        static::setupDatabase();
    }

    public static function tearDownAfterClass()
    {
    }

    public function getEntityManager()
    {
        global $kernel;
        return $kernel->getContainer()->get('doctrine.orm.entity_manager');
    }

    public function getIndexer()
    {
        global $kernel;
        return $kernel->getContainer()->get('algolia.indexer');
    }

    public function persistAndFlush($entity)
    {
        $this->getEntityManager()->persist($entity);
        $this->getEntityManager()->flush();
        return $this;
    }

    public function removeAndFlush($entity)
    {
        $this->getEntityManager()->remove($entity);
        $this->getEntityManager()->flush();
        return $this;
    }

    public function setUp()
    {
        $this->getIndexer()->reset();
        $this->getIndexer()->isolateFromAlgolia(static::$isolateFromAlgolia);
    }

    public function getObjectID(array $primaryKeyData)
    {
        return $this->getIndexer()->serializePrimaryKey($primaryKeyData);
    }
}