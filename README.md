# GitHub to MySQL

Synchronizes GitHub data (issues, labels, ...) to a MySQL database.

Features:

- [x] synchronize issues and pull requests
    - [x] support multiple pages of results
- [x] synchronize labels
- [x] synchronize milestones

## Getting started

- clone the repository or [download a stable release](https://github.com/wizaplace/github-to-mysql/releases) and unzip it
- copy `.env.dist` to create a `.env` file
- create the DB tables by running `./github-to-mysql db-init --force`
    You can check which DB queries will be run by removing the `--force` option (the queries will NOT be run if the option is missing).
    
You can also simply run `./github-to-mysql` without arguments and follow the instructions.

The `.env` file contains the configuration to connect to the MySQL database as well as the GitHub token. Alternatively to using this file you can set up all the environment variables it contains.

## Usage

```
./github-to-mysql sync user/repository
```
