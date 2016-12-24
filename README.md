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

## Use cases

With GitHub data in MySQL, you can extract interesting metrics. Here are a few examples:

- discover labels with the most issues or pull requests

- follow number of issues planned per milestone

- ratio of pull requests VS issues

    ```sql
    SELECT count(*) AS count, IF(is_pull_request, 'Pull request', 'Issue') AS is_pull_request
    FROM github_issues
    GROUP BY is_pull_request
    ORDER BY is_pull_request ASC
    ```

    ![](https://i.imgur.com/3xlF5vn.png)

- average number of days to merge a pull request over the past weeks
    
    ```sql
    SELECT
    	YEARWEEK(closed_at) AS week,
    	AVG(TIMESTAMPDIFF(DAY, created_at, closed_at))
    FROM github_issues
    WHERE open = 0
        AND is_pull_request = 1
    GROUP BY week;
    ```

    ![](https://i.imgur.com/PH0CK70.png)

- average number of pull requests open every day

    ```sql
    SELECT
    	DATE(updated_at) AS day,
    	(SELECT COUNT(*) FROM github_issues WHERE is_pull_request = 1 AND created_at < day AND (closed_at >= day OR open = 1)) AS pr_open
    FROM github_issues
    WHERE updated_at IS NOT NULL
    GROUP BY day;
    ```
    
    ![](https://i.imgur.com/AWYIDom.png)

- total number of issues

    ![](https://i.imgur.com/WvIQMeI.png)

Those are just examples to illustrate the possibilities, we hope it will give you some ideas.
