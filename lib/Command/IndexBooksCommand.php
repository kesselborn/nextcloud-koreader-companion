<?php

declare(strict_types=1);

namespace OCA\KoreaderCompanion\Command;

use OCA\KoreaderCompanion\Service\BookService;
use OCP\IUserManager;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Extract metadata for books still waiting on it.
 *
 * Extraction normally happens in ExtractMetadataJob, which needs working cron.
 * When cron has been broken or disabled, books pile up at
 * indexing_state='pending', listed under their filename with no author, and
 * nothing in the admin's toolbox says how to clear that: `background-job:worker`
 * only runs what is still queued, and a job lost to a database restore or a
 * queue purge is never coming back.
 *
 * This walks the pending rows directly rather than the queue, so it fixes both
 * cases. Safe to re-run; rows already done are skipped.
 */
#[AsCommand(
    name: 'koreader:index',
    description: 'Extract metadata for books still marked pending',
)]
class IndexBooksCommand extends Command {

    public function __construct(
        private BookService $bookService,
        private IUserManager $userManager,
    ) {
        parent::__construct();
    }

    protected function configure(): void {
        $this
            ->setHelp(
                'Extracts metadata for books whose indexing is still pending. Use this when '
                . 'background jobs have not been running: books stay listed under their filename '
                . 'until their metadata is extracted. Safe to re-run.'
            )
            ->addOption(
                'user',
                'u',
                InputOption::VALUE_OPTIONAL,
                'Only process books belonging to this user'
            )
            ->addOption(
                'limit',
                'l',
                InputOption::VALUE_OPTIONAL,
                'Maximum books to process per user in one run (default: all pending)'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int {
        $userId = $input->getOption('user');
        $limit = $input->getOption('limit');

        $userIds = [];
        if ($userId !== null) {
            if (!$this->userManager->userExists($userId)) {
                $output->writeln("<error>No such user: {$userId}</error>");
                return Command::FAILURE;
            }
            $userIds[] = $userId;
        } else {
            $this->userManager->callForSeenUsers(function ($user) use (&$userIds): void {
                $userIds[] = $user->getUID();
            });
        }

        $totalProcessed = 0;
        $totalFailed = 0;

        // Every count happens before any extraction writes. Counting between
        // passes would read a table this process has already written, which
        // Nextcloud's "dirty table reads" assertion rejects with debug enabled.
        $pendingPerUser = [];
        foreach ($userIds as $uid) {
            $pending = $this->bookService->countPendingBooks($uid);
            if ($pending > 0) {
                $pendingPerUser[$uid] = $pending;
            }
        }

        foreach ($pendingPerUser as $uid => $pending) {
            // One pass per user, sized to the backlog, rather than looping over
            // batches -- for the same reason.
            $batch = $limit !== null ? (int)$limit : $pending;

            $result = $this->bookService->processPendingBooks($uid, $batch);
            $totalProcessed += $result['processed'];
            $totalFailed += $result['failed'];

            $output->writeln(sprintf(
                '%s: %d extracted, %d failed, %d remaining',
                $uid,
                $result['processed'],
                $result['failed'],
                $result['remaining']
            ));
        }

        if ($totalProcessed === 0 && $totalFailed === 0) {
            $output->writeln('Nothing pending.');
            return Command::SUCCESS;
        }

        $output->writeln(sprintf('<info>Done: %d extracted, %d failed.</info>', $totalProcessed, $totalFailed));

        return $totalFailed > 0 ? Command::FAILURE : Command::SUCCESS;
    }
}
