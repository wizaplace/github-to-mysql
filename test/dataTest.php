<?php

namespace GitHubToMysql\Tests;

use PHPUnit\Framework\TestCase;
use Doctrine\DBAL\DriverManager;
use GitHubToMysql\data;

class dataTest extends TestCase {

    /**
     * The connection
     *
     * @var \Doctrine\DBAL\Connection
     */
    private $db;

    public function setUp(): void {
        $dbConfig = new \Doctrine\DBAL\Configuration();
        $dbConfig->setFilterSchemaAssetsExpression('/^github_/');
        $this->db = DriverManager::getConnection([
            'driver' => 'pdo_sqlite',
        ], $dbConfig);

        data::createSchema($this->db, true);
    }

    public function testCreateLabelsFromJson(): void {
        $testLabels = [
            [
                'id' => '1',
                'url' => 'https://gh.com/label',
                'name' => 'Test label',
                'color' => '#aeaeae',
            ],
            [
                'id' => '2',
                'url' => 'https://gh.com/bug',
                'name' => 'bug',
                'color' => '#efefef',
            ]
        ];
        $labels = [];
        data::createLabelsFromJson($this->db, json_encode($testLabels), function ($label) use (&$labels) {
            $labels[] = $label;
        });
        $this->assertSame($testLabels, $labels);
        $this->assertSame($testLabels, $this->db->createQueryBuilder()->select('*')->from('github_labels')->execute()->fetchAll());
    }

    public function testReCreateLabelsFromJson(): void {
        $testLabels = [
            [
                'id' => '1',
                'url' => 'https://gh.com/label',
                'name' => 'Test label',
                'color' => '#aeaeae',
            ],
            [
                'id' => '2',
                'url' => 'https://gh.com/bug',
                'name' => 'bug',
                'color' => '#efefef',
            ]
        ];

        $additionalLabels = [
            [
                'id' => '1',
                'url' => 'https://gh.com/updated_link',
                'name' => 'Updated label name',
                'color' => '#123edf',
            ],
        ];

        $labels = [];
        data::createLabelsFromJson($this->db, json_encode($testLabels), function ($label) use (&$labels) {
            $labels[] = $label;
        });
        $this->assertSame($testLabels, $labels);

        data::createLabelsFromJson($this->db, json_encode($additionalLabels), function ($label) use (&$labels) {
            $labels[] = $label;
        });
        $this->assertSame(array_merge($testLabels, $additionalLabels), $labels);
        $testLabels[0] = $additionalLabels[0];
        $this->assertSame($testLabels, $this->db->createQueryBuilder()->select('*')->from('github_labels')->execute()->fetchAll());
    }

}