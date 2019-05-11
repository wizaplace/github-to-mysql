<?php

namespace GitHubToMysql\Tests;

use PHPUnit\Framework\TestCase;
use Doctrine\DBAL\DriverManager;
use GitHubToMysql\Github;
use GitHubToMysql\DbSchema;

class GithubTest extends TestCase {

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

        DbSchema::createSchema($this->db, true);
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
        Github::createLabelsFromJson($this->db, $testLabels, function ($label) use (&$labels) {
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
        Github::createLabelsFromJson($this->db, $testLabels, function ($label) use (&$labels) {
            $labels[] = $label;
        });
        $this->assertSame($testLabels, $labels);

        Github::createLabelsFromJson($this->db, $additionalLabels, function ($label) use (&$labels) {
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
        Github::createMilestonesFromJson($this->db, $testMilestones, function ($milestone) use (&$milestones) {
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
        Github::createMilestonesFromJson($this->db, $testMilestones, function ($milestone) use (&$milestones) {
            $milestones[] = $milestone;
        });
        $this->assertSame($testMilestones, $milestones);

        Github::createMilestonesFromJson($this->db, $additionalMilestones, function ($milestone) use (&$milestones) {
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

    public function testCreateIssues(): void {
        $testIssues = [
            [
                'number' => '1',
                'title' => 'A bug in version 1.0.0',
                'user' => [
                    'login' => 'williamdes',
                    'avatar_url' => 'https://github.com/images/error/octocat_happy.gif'
                ],
                'created_at' => '2019-04-24T13:33:48Z',
                'updated_at' => '2019-04-24T13:33:48Z',
                'closed_at' => null,
                'pull_request' => [
                    "url" => "https://api.github.com/repos/octocat/Hello-World/pulls/1347",
                    "html_url" => "https://github.com/octocat/Hello-World/pull/1347",
                    "diff_url" => "https://github.com/octocat/Hello-World/pull/1347.diff",
                    "patch_url" => "https://github.com/octocat/Hello-World/pull/1347.patch",
                ],
                'state' => 'open',
                'milestone' => [
                    'number' => '1',
                ],
                "labels" => []
            ],
            [
                'number' => '2',
                'title' => 'Another bug in version 2.0.0',
                'user' => [
                    'login' => 'williamdes',
                    'avatar_url' => 'https://github.com/images/error/octocat_happy.gif'
                ],
                'created_at' => '2019-04-24T13:50:48Z',
                'updated_at' => '2019-04-24T13:50:48Z',
                'closed_at' => null,
                'state' => 'open',
                'milestone' => [
                    'number' => '2',
                ],
                "labels" => [
                    [
                        "id" => '208045946',
                        "node_id" => "MDU6TGFiZWwyMDgwNDU5NDY=",
                        "url" => "https://api.github.com/repos/octocat/Hello-World/labels/bug",
                        "name" => "bug",
                        "description" => "Something isn't working",
                        "color" => "f29513",
                        "default" => true
                    ]
                ],
            ],
            [
                'number' => '3',
                'title' => 'Another bug in version 2.1.0',
                'user' => [
                    'login' => 'williamdes',
                    'avatar_url' => 'https://github.com/images/error/octocat_happy.gif'
                ],
                'created_at' => '2019-04-24T13:50:48Z',
                'updated_at' => '2019-04-24T13:50:48Z',
                'closed_at' => '2019-04-24T13:50:48Z',
                'state' => 'closed',
                'milestone' => [
                    'number' => '3',
                ],
                "labels" => [
                    [
                        "id" => '208045946',
                        "node_id" => "MDU6TGFiZWwyMDgwNDU5NDY=",
                        "url" => "https://api.github.com/repos/octocat/Hello-World/labels/bug",
                        "name" => "bug",
                        "description" => "Something isn't working",
                        "color" => "f29513",
                        "default" => true
                    ]
                ],
            ],
        ];
        $issues = [];
        $phpunit = $this;
        Github::createIssues($this->db, $testIssues, function (bool $isCreation, array $issue) use (&$issues, $phpunit) {
            $issues[] = $issue;
            $phpunit->assertTrue($isCreation);
        });
        $this->assertSame($testIssues, $issues);
        $dbIssues = $this->db->createQueryBuilder()->select('*')->from('github_issues')->execute()->fetchAll();
        foreach($testIssues as &$issue) {
            $issue = [// Re create the array like in the database (yes, this is partial data)
                'id' => $issue['number'],
                'milestone_id' => $issue['milestone']['number'],
                'title' => $issue['title'],
                'open' => ($issue['state'] === 'open') ? '1' : '0',
                'author' => $issue['user']['login'],
                'author_avatar_url' => $issue['user']['avatar_url'],
                'created_at' => date('Y-m-d H:i:s', strtotime($issue['created_at'])),
                'updated_at' => date('Y-m-d H:i:s', strtotime($issue['updated_at'])),
                'closed_at' => $issue['closed_at'] ? date('Y-m-d H:i:s', strtotime($issue['closed_at'])) : null,
                'is_pull_request' => isset($issue['pull_request']) ? '1' : '0',
            ];
        }
        $this->assertSame($testIssues, $dbIssues);
    }

}