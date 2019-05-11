<?php

namespace GitHubToMysql;

use Doctrine\DBAL\Schema\Schema;

/**
 * The database schema class
 */
class DbSchema {

    /**
     * Get the database schema
     *
     * @return Schema The schema with all tables and foreign keys
     */
    public static function createSchema(): Schema {

        $schema = new Schema();

        // Labels
        $labelsTable = $schema->createTable('github_labels');
        $labelsTable->addColumn('id', 'integer', ['unsigned' => true]);
        $labelsTable->addColumn('url', 'string');
        $labelsTable->addColumn('name', 'string');
        $labelsTable->addColumn('color', 'string');
        $labelsTable->setPrimaryKey(['id']);
        $labelsTable->addIndex(['name']);
        // Milestones
        $milestonesTable = $schema->createTable('github_milestones');
        $milestonesTable->addColumn('id', 'integer', ['unsigned' => true]);
        $milestonesTable->addColumn('title', 'string');
        $milestonesTable->addColumn('description', 'string');
        $milestonesTable->addColumn('url', 'string');
        $milestonesTable->addColumn('open', 'boolean');
        $milestonesTable->setPrimaryKey(['id']);
        $milestonesTable->addIndex(['title']);
        // Issues
        $issuesTable = $schema->createTable('github_issues');
        $issuesTable->addColumn('id', 'integer', ['unsigned' => true]);
        $issuesTable->addColumn('title', 'text');
        $issuesTable->addColumn('open', 'boolean');
        $issuesTable->addColumn('author', 'string');
        $issuesTable->addColumn('author_avatar_url', 'string', ['notnull' => false]);
        $issuesTable->addColumn('created_at', 'datetime');
        $issuesTable->addColumn('updated_at', 'datetime');
        $issuesTable->addColumn('closed_at', 'datetime', ['notnull' => false]);
        $issuesTable->addColumn('is_pull_request', 'boolean');
        $issuesTable->addColumn('milestone_id', 'integer', ['unsigned' => true, 'notnull' => false]);
        $issuesTable->setPrimaryKey(['id']);
        $issuesTable->addIndex(['author']);
        $issuesTable->addIndex(['open']);
        $issuesTable->addIndex(['created_at']);
        $issuesTable->addIndex(['updated_at']);
        $issuesTable->addIndex(['closed_at']);
        $issuesTable->addIndex(['is_pull_request']);
        $issuesTable->addForeignKeyConstraint('github_milestones', ['milestone_id'], ['id']);
        // Issue labels
        $issueLabelsTable = $schema->createTable('github_issue_labels');
        $issueLabelsTable->addColumn('issue_id', 'integer', ['unsigned' => true]);
        $issueLabelsTable->addColumn('label_id', 'integer', ['unsigned' => true]);
        $issueLabelsTable->setPrimaryKey(['issue_id', 'label_id']);
        $issueLabelsTable->addForeignKeyConstraint('github_issues', ['issue_id'], ['id'], [
            'onUpdate' => 'CASCADE',
            'onDelete' => 'CASCADE',
        ]);
        $issueLabelsTable->addForeignKeyConstraint('github_labels', ['label_id'], ['id'], [
            'onUpdate' => 'CASCADE',
            'onDelete' => 'CASCADE',
        ]);

        return $schema;
    }
}