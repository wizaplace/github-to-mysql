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


    public function testCreateMilestonesFromJson(): void {
        $testMilestones = [
            [
                'number' => '1',
                'title' => 'Version 1.0.0',
                'description' => 'A Description',
                'state' => 'open',
                'url' => 'https://gh.com/mile/stone/1.0.0'
            ],
            [
                'number' => '2',
                'title' => 'Version 2.0.0',
                'description' => 'V2.0.0',
                'state' => 'open',
                'url' => 'https://gh.com/mile/stone/2.0.0'
            ]
        ];
        $milestones = [];
        data::createMilestonesFromJson($this->db, json_encode($testMilestones), function ($milestone) use (&$milestones) {
            $milestones[] = $milestone;
        });
        $this->assertSame($testMilestones, $milestones);
        $dbMilestones = $this->db->createQueryBuilder()->select('id as number, title, description, open as state, url')->from('github_milestones')->execute()->fetchAll();
        foreach($dbMilestones as &$milestone) {
            $milestone['state'] = ($milestone['state'] === '1') ? 'open' : 'closed';
        }
        $this->assertSame($testMilestones, $dbMilestones);
    }

    public function testReCreateMilestonesFromJson(): void {
        $testMilestones = [
            [
                'number' => '1',
                'title' => 'Version 1.0.0',
                'description' => 'A Description',
                'state' => 'open',
                'url' => 'https://gh.com/mile/stone/1.0.0'
            ],
            [
                'number' => '2',
                'title' => 'Version 2.0.0',
                'description' => 'V2.0.0',
                'state' => 'open',
                'url' => 'https://gh.com/mile/stone/2.0.0'
            ]
        ];

        $additionalMilestones = [
            [
                'number' => '1',
                'title' => 'V 1.0.0',
                'description' => 'Version 1.0.0',
                'state' => 'closed',
                'url' => 'https://gh.com/milestone/1.0.0'
            ],
        ];

        $milestones = [];
        data::createMilestonesFromJson($this->db, json_encode($testMilestones), function ($milestone) use (&$milestones) {
            $milestones[] = $milestone;
        });
        $this->assertSame($testMilestones, $milestones);

        data::createMilestonesFromJson($this->db, json_encode($additionalMilestones), function ($milestone) use (&$milestones) {
            $milestones[] = $milestone;
        });
        $this->assertSame(array_merge($testMilestones, $additionalMilestones), $milestones);
        $testMilestones[0] = $additionalMilestones[0];

        $dbMilestones = $this->db->createQueryBuilder()->select('id as number, title, description, open as state, url')->from('github_milestones')->execute()->fetchAll();
        foreach($dbMilestones as &$milestone) {
            $milestone['state'] = ($milestone['state'] === '1') ? 'open' : 'closed';
        }

        $this->assertSame($testMilestones, $dbMilestones);
    }
}