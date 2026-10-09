<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\UserRepository;
use App\Service\DemoSeeder;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:seed-demo', description: 'Install preset templates and create the admin and demo accounts')]
final class SeedDemoCommand extends Command
{
    public function __construct(private readonly DemoSeeder $seeder, private readonly UserRepository $users)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('if-empty', null, InputOption::VALUE_NONE, 'Only seed when the database has no users yet')
            ->addOption('admin-email', null, InputOption::VALUE_REQUIRED, 'Admin email', getenv('CVOLVE_ADMIN_EMAIL') ?: 'admin@cvolve.local')
            ->addOption('admin-password', null, InputOption::VALUE_REQUIRED, 'Admin password', getenv('CVOLVE_ADMIN_PASSWORD') ?: 'admin1234')
            ->addOption('no-demo', null, InputOption::VALUE_NONE, 'Skip the demo user');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $templates = $this->seeder->installPresets();
        $io->writeln('Preset templates: '.implode(', ', $templates));

        if ($input->getOption('if-empty') && $this->users->count([]) > 0) {
            $io->note('Users already exist; skipping accounts.');

            return Command::SUCCESS;
        }

        $admin = $this->seeder->createUser((string) $input->getOption('admin-email'), (string) $input->getOption('admin-password'), 'Administrator', true);
        $io->writeln(sprintf('Admin: <info>%s</info>', $admin->getEmail()));
        if (!$input->getOption('no-demo')) {
            $demo = $this->seeder->seedDemoUser();
            $io->writeln(sprintf('Demo user: <info>%s</info> / <info>%s</info>', $demo->getEmail(), DemoSeeder::DEMO_PASSWORD));
        }
        $io->success('Seeding complete.');

        return Command::SUCCESS;
    }
}
