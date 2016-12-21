# GitHub to MySQL

Synchronizes GitHub data (issues, labels, ...) to a MySQL database.

Progress:

- [x] synchronize issues and pull requests
    - [x] support multiple pages of results
- [x] synchronize labels
- [ ] synchronize milestones

## Installation

- clone the repository
- copy `.env.dist` to create a `.env` file

The `.env` file contains the configuration to connect to the MySQL database as well as the GitHub token. Alternatively to using this file you can set up all the environment variables it contains.

## Usage

```
php app.php sync organization/repository
```
