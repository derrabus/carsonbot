<?php

namespace App\Api\Issue;

use App\Model\Repository;
use Github\Api\Issue;
use Github\Api\Issue\Comments;
use Github\Api\Search;
use Github\ResultPager;

class GithubIssueApi implements IssueApi
{
    private $resultPager;
    private $issueCommentApi;
    private $issueApi;
    private $searchApi;
    private $botUsername;

    public function __construct(ResultPager $resultPager, Comments $issueCommentApi, Issue $issueApi, Search $searchApi, string $botUsername)
    {
        $this->resultPager = $resultPager;
        $this->issueCommentApi = $issueCommentApi;
        $this->issueApi = $issueApi;
        $this->searchApi = $searchApi;
        $this->botUsername = $botUsername;
    }

    public function open(Repository $repository, string $title, string $body, array $labels)
    {
        $params = [
            'title' => $title,
            'labels' => $labels,
            'body' => $body,
        ];

        $issueNumber = null;
        $existingIssues = $this->resultPager->fetchAllLazy($this->searchApi, 'issues', [sprintf('repo:%s "%s" is:open author:%s', $repository->getFullName(), $title, $this->botUsername), 'updated', 'desc']);
        foreach ($existingIssues as $issue) {
            $issueNumber = $issue['number'];
        }

        if (null === $issueNumber) {
            $this->issueApi->create($repository->getVendor(), $repository->getName(), $params);
        } else {
            unset($params['labels']);
            $this->issueApi->update($repository->getVendor(), $repository->getName(), $issueNumber, $params);
        }
    }

    public function lastCommentWasMadeByBot(Repository $repository, $number): bool
    {
        $allComments = $this->issueCommentApi->all($repository->getVendor(), $repository->getName(), $number, ['per_page' => 100]);
        $lastComment = $allComments[count($allComments) - 1] ?? [];

        return $this->botUsername === ($lastComment['user']['login'] ?? null);
    }

    public function show(Repository $repository, $issueNumber): array
    {
        return $this->issueApi->show($repository->getVendor(), $repository->getName(), $issueNumber);
    }

    public function close(Repository $repository, $issueNumber)
    {
        $this->issueApi->update($repository->getVendor(), $repository->getName(), $issueNumber, ['state' => 'closed']);
    }

    /**
     * This will comment on both Issues and Pull Requests.
     */
    public function commentOnIssue(Repository $repository, $issueNumber, string $commentBody)
    {
        $this->issueCommentApi->create(
            $repository->getVendor(),
            $repository->getName(),
            $issueNumber,
            ['body' => $commentBody]
        );
    }

    public function findStaleIssues(Repository $repository, \DateTimeImmutable $noUpdateAfter): iterable
    {
        return $this->resultPager->fetchAllLazy($this->searchApi, 'issues', [sprintf('repo:%s is:issue -label:"Keep open" -label:"Missing translations" is:open updated:<%s', $repository->getFullName(), $noUpdateAfter->format('Y-m-d')), 'updated', 'desc']);
    }
}
