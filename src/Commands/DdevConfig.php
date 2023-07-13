<?php

namespace Augustash\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputArgument;

/**
 * Console command.
 */
class DdevConfig extends Command {

  /**
   * Configure.
   */
  protected function configure() {
    $this->setName('hello-world')
      ->setDescription('Prints Hello-World!')
      ->setHelp('Demonstration of custom commands created by Symfony Console component.')
      ->addArgument('username', InputArgument::REQUIRED, 'Pass the username.');
  }

  /**
   * Execute.
   */
  protected function execute(InputInterface $input, OutputInterface $output) {
    $output->writeln(sprintf('Hello World!, %s', $input->getArgument('username')));
  }

}
