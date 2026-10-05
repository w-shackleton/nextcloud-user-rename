<?php

declare(strict_types=1);

namespace OCA\UserRename\Command;

use OCA\UserRename\Service\RenameOptions;
use OCA\UserRename\Service\RenameService;
use OCA\UserRename\Service\Scanner;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Read-only: shows where a uid is referenced. Useful before a rename, and
 * afterwards with the old uid to confirm nothing was left behind.
 */
class Scan extends Command {
	public function __construct(
		private RenameService $renameService,
		private Scanner $scanner,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this
			->setName('user-rename:scan')
			->setDescription('Show where a uid is referenced in the database (read-only)')
			->addArgument('uid', InputArgument::REQUIRED, 'uid to look for (case-sensitive)');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$uid = (string)$input->getArgument('uid');
		$options = new RenameOptions();

		$output->writeln("<info>Covered references to \"$uid\":</info>");
		$counts = $this->renameService->countReferences($uid, $options);
		if ($counts === []) {
			$output->writeln('  (none)');
		} else {
			$table = new Table($output);
			$table->setHeaders(['Handler', 'Column', 'Rows']);
			foreach ($counts as $count) {
				$table->addRow([$count['handler'], $count['column'], $count['rows']]);
			}
			$table->render();
		}

		$output->writeln('');
		$output->writeln("<info>Other possible references to \"$uid\" (not handled by user:rename):</info>");
		$hits = $this->scanner->scan($uid, $this->renameService->coveredColumns($options));
		if ($hits === []) {
			$output->writeln('  (none)');
		} else {
			$table = new Table($output);
			$table->setHeaders(['Table', 'Column', 'Match', 'Rows']);
			foreach ($hits as $hit) {
				$table->addRow([$hit['table'], $hit['column'], $hit['match'], $hit['rows']]);
			}
			$table->render();
		}
		return self::SUCCESS;
	}
}
