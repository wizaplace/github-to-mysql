# GitHub to MySQL

Synchronizes GitHub data (issues, labels, ...) to a MySQL database.

Features:

- [x] synchronize issues and pull requests
    - [x] support multiple pages of results
- [x] synchronize labels
- [x] synchronize milestones

## Installation

- clone the repository
- copy `.env.dist` to create a `.env` file
- create the DB tables by running `php app.php db-init --force`
    You can check which DB queries will be run by removing the `--force` option (the queries will NOT be run if the option is missing).

The `.env` file contains the configuration to connect to the MySQL database as well as the GitHub token. Alternatively to using this file you can set up all the environment variables it contains.

## Usage

```
php app.php sync organization/repository
```
