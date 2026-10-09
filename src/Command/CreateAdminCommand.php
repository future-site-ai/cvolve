<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\DemoSeeder;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:create-admin', description: 'Create an administrator (or promote and reset an existing account)')]
final class CreateAdminCommand extends Command
{
    public function __construct(private readonly DemoSeeder $seeder)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('email', InputArgument::REQUIRED)
            ->addOption('password', 'p', InputOption::VALUE_REQUIRED, 'Password (asked interactively when omitted)')
            ->addOption('name', null, InputOption::VALUE_REQUIRED, 'Full name', 'Administrator');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $email = (string) $input->getArgument('email');
        if (!filter_var($email, \FILTER_VALIDATE_EMAIL)) {
            $io->error('Invalid email address.');

            return Command::INVALID;
        }
        $password = $input->getOption('password') ?? $io->askHidden('Password (min. 8 characters)');
        if (!\is_string($password) || \strlen($password) < 8) {
            $io->error('The password must have at least 8 characters.');

            return Command::INVALID;
        }

        $this->seeder->createUser($email, $password, (string) $input->getOption('name'), true);
        $io->success(sprintf('%s is an administrator.', $email));

        return Command::SUCCESS;
    }
}
