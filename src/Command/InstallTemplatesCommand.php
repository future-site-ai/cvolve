<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\TemplateRepository;
use App\Service\Cv\CvRenderer;
use App\Service\DemoSeeder;
use App\Service\Export\GotenbergClient;
use App\Service\Storage;
use Cvolve\TemplateKit\Package\InvalidTemplatePackageException;
use App\Service\Template\TemplateInstaller;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:templates:install', description: 'Install the bundled presets, or a given .cvolve file / directory as a preset')]
final class InstallTemplatesCommand extends Command
{
    public function __construct(
        private readonly DemoSeeder $seeder,
        private readonly TemplateInstaller $installer,
        private readonly TemplateRepository $templates,
        private readonly CvRenderer $renderer,
        private readonly GotenbergClient $gotenberg,
        private readonly Storage $storage,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('path', InputArgument::OPTIONAL, 'A .cvolve file or an unpacked template directory')
            ->addOption('thumbnails', null, InputOption::VALUE_NONE, 'Render missing thumbnails with Gotenberg')
            ->addOption('pack', null, InputOption::VALUE_REQUIRED, 'Also write the installed presets as .cvolve files into this directory');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $path = $input->getArgument('path');

        try {
            if (\is_string($path)) {
                $template = is_dir($path) ? $this->installer->installDirectory($path) : $this->installer->installZip($path);
                $io->success(sprintf('Installed "%s" as a preset.', $template->getName()));
            } else {
                $io->success('Installed presets: '.implode(', ', $this->seeder->installPresets()));
            }
        } catch (InvalidTemplatePackageException $e) {
            $io->error($e->errors);

            return Command::FAILURE;
        }

        if ($input->getOption('thumbnails')) {
            foreach ($this->templates->findPresets() as $template) {
                if ($this->installer->thumbnailPath($template) !== null) {
                    continue;
                }
                try {
                    $png = $this->gotenberg->screenshot($this->renderer->renderSample($template, CvRenderer::MODE_PRINT));
                    $this->storage->write('templates/'.$template->getStorageKey().'/thumbnail.png', $png);
                    $io->writeln(sprintf('Thumbnail rendered for %s', $template->getName()));
                } catch (\Throwable $e) {
                    $io->warning(sprintf('No thumbnail for %s: %s', $template->getName(), $e->getMessage()));
                }
            }
        }

        if (\is_string($dir = $input->getOption('pack'))) {
            @mkdir($dir, 0775, true);
            foreach ($this->templates->findPresets() as $template) {
                $target = rtrim($dir, '/').'/'.$template->getSlug().'.cvolve';
                rename($this->installer->export($template), $target);
                $io->writeln('Packed '.$target);
            }
        }

        return Command::SUCCESS;
    }
}
