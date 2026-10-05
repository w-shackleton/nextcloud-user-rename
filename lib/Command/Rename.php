<?php

declare(strict_types=1);

namespace OCA\UserRename\Command;

use OCA\UserRename\Exception\PreflightException;
use OCA\UserRename\Exception\RenameFailedException;
use OCA\UserRename\Service\RenameOptions;
use OCA\UserRename\Service\RenameReport;
use OCA\UserRename\Service\RenameService;
use OCP\IConfig;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;

class Rename extends Command {
	public function __construct(
		private RenameService $renameService,
		private IConfig $config,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this
			->setName('user:rename')
			->setDescription('Rename a local user\'s uid (login name)')
			->setHelp(<<<'HELP'
Renames a local (Database backend) user across the database and the data
directory. Maintenance mode must be OFF when you start: occ does not load app
commands in maintenance mode, so this command switches it on itself and
switches it off again when done.

Always run with --dry-run first, and take a database + data directory backup.
All desktop/mobile clients of the user must remove and re-add the account.
HELP)
			->addArgument('old', InputArgument::REQUIRED, 'Current uid')
			->addArgument('new', InputArgument::REQUIRED, 'New uid')
			->addOption('dry-run', null, InputOption::VALUE_NONE, 'Run all checks and show what would change')
			->addOption('keep-tokens', null, InputOption::VALUE_NONE, 'Keep sessions and app passwords instead of deleting them')
			->addOption('force', null, InputOption::VALUE_NONE, 'Continue even if rows already reference the new uid')
			->addOption('strict', null, InputOption::VALUE_NONE, 'Abort if the scanner finds references no handler covers');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$old = (string)$input->getArgument('old');
		$new = (string)$input->getArgument('new');
		$options = new RenameOptions(
			dryRun: (bool)$input->getOption('dry-run'),
			keepTokens: (bool)$input->getOption('keep-tokens'),
			force: (bool)$input->getOption('force'),
			strict: (bool)$input->getOption('strict'),
		);

		if ($this->config->getSystemValueBool('maintenance')) {
			$output->writeln('<error>Turn maintenance mode off first; this command enables it itself.</error>');
			return self::FAILURE;
		}

		try {
			$report = $this->renameService->plan($old, $new, $options);
		} catch (PreflightException $e) {
			$output->writeln('<error>' . $e->getMessage() . '</error>');
			return self::FAILURE;
		}
		$this->printReport($output, $report, $options->dryRun ? 'Would change' : 'Will change');

		$blocked = null;
		if ($report->conflicts !== [] && !$options->force) {
			$blocked = "Rows already reference \"$new\" (see above); clean them up or pass --force to merge them into the renamed account.";
		} elseif ($report->unknownReferences !== [] && $options->strict) {
			$blocked = "Uncovered references to \"$old\" found and --strict is set.";
		}

		if ($options->dryRun) {
			$output->writeln('');
			if ($blocked !== null) {
				$output->writeln("<error>$blocked</error>");
				return self::FAILURE;
			}
			$output->writeln('<info>Dry run, nothing changed.</info>');
			return self::SUCCESS;
		}
		if ($blocked !== null) {
			$output->writeln("<error>$blocked</error>");
			return self::FAILURE;
		}

		if ($input->isInteractive()) {
			$question = new ConfirmationQuestion("\nRename \"$old\" to \"$new\"? Make sure you have a backup. [y/N] ", false);
			if (!$this->getHelper('question')->ask($input, $output, $question)) {
				$output->writeln('Aborted.');
				return self::FAILURE;
			}
		}

		$output->writeln('Enabling maintenance mode');
		$this->config->setSystemValue('maintenance', true);
		try {
			$report = $this->renameService->rename($old, $new, $options);
		} catch (PreflightException|RenameFailedException $e) {
			$output->writeln('<error>' . $e->getMessage() . '</error>');
			return self::FAILURE;
		} finally {
			$this->config->setSystemValue('maintenance', false);
			$output->writeln('Disabled maintenance mode');
		}

		$output->writeln('');
		$this->printReport($output, $report, 'Changed');
		$this->syncSystemAddressBook($output);

		$output->writeln('');
		$output->writeln("<info>Renamed \"$old\" to \"$new\".</info>");
		$output->writeln('Next steps:');
		$output->writeln(' - Restart php-fpm / your web server, or flush APCu/Redis, to drop cached user data');
		$output->writeln(" - Remove and re-add the account in every desktop/mobile client (WebDAV URLs contain the uid)");
		$output->writeln(" - The federated cloud ID changed to $new@<host>; federated shares with other servers may need re-creating");
		return self::SUCCESS;
	}

	private function printReport(OutputInterface $output, RenameReport $report, string $title): void {
		$output->writeln("<info>$title for \"{$report->old}\" -> \"{$report->new}\":</info>");
		if ($report->changes === []) {
			$output->writeln('  (no rows)');
		} else {
			$table = new Table($output);
			$table->setHeaders(['Handler', 'Column', 'Rows']);
			foreach ($report->changes as $change) {
				$table->addRow([$change['handler'], $change['column'], $change['rows']]);
			}
			$table->render();
		}

		if ($report->conflicts !== []) {
			$output->writeln('');
			$output->writeln("<comment>Rows already referencing \"{$report->new}\" (left over from a deleted user?):</comment>");
			$table = new Table($output);
			$table->setHeaders(['Handler', 'Column', 'Rows']);
			foreach ($report->conflicts as $conflict) {
				$table->addRow([$conflict['handler'], $conflict['column'], $conflict['rows']]);
			}
			$table->render();
		}

		if ($report->unknownReferences !== []) {
			$output->writeln('');
			$output->writeln("<comment>Possible references to \"{$report->old}\" that no handler covers (not changed):</comment>");
			$table = new Table($output);
			$table->setHeaders(['Table', 'Column', 'Match', 'Rows']);
			foreach ($report->unknownReferences as $hit) {
				$table->addRow([$hit['table'], $hit['column'], $hit['match'], $hit['rows']]);
			}
			$table->render();
		}

		foreach ($report->warnings as $warning) {
			$output->writeln("<comment>Warning: $warning</comment>");
		}
	}

	/**
	 * Rebuilds the system address book card ("Database:<uid>.vcf") in a fresh
	 * process: this one still has the old user cached in IUserManager and the
	 * Database backend, so the stale card would not be removed.
	 */
	private function syncSystemAddressBook(OutputInterface $output): void {
		$command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(\OC::$SERVERROOT . '/occ')
			. ' dav:sync-system-addressbook --no-interaction';
		$output->writeln('Refreshing the system address book');
		passthru($command . ' 2>&1', $exitCode);
		if ($exitCode !== 0) {
			$output->writeln('<comment>dav:sync-system-addressbook failed; run "occ dav:sync-system-addressbook" by hand.</comment>');
		}
	}
}
