# GitHub to MySQL

Synchronizes GitHub data (issues, labels, ...) to a MySQL database.

Progress:

- [x ] synchronize issues and pull requests
    - [ ] support multiple pages of results
- [x] synchronize labels
- [ ] synchronize milestones

## Installation

- clone the repository
- copy `.env.dist` to create a `.env` file

The `.env` file contains the configuration to connect to the MySQL database as well as the GitHub token.

## Usage

```
php app.php sync organization/repository
```
