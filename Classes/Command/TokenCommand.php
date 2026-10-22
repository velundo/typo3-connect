<?php

declare(strict_types=1);

namespace Velundo\Connect\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Velundo\Connect\Service\TokenService;

/**
 * vendor/bin/typo3 velundo:token create "<Bezeichnung>" | list | revoke <Kennung>
 */
final class TokenCommand extends Command
{
    public function __construct(private readonly TokenService $tokens)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Zugangstoken für die velundo-App erzeugen, auflisten oder widerrufen')
            ->setHelp(
                "Beispiele:\n" .
                "  velundo:token create \"Agentur Test\"   Token erzeugen (wird nur einmal angezeigt)\n" .
                "  velundo:token create \"Agentur Test\" --allow-publish --allow-live-edit\n" .
                "  velundo:token list                     Tokens auflisten (mit Rechten)\n" .
                "  velundo:token revoke 1a2b3c4d           Token widerrufen\n\n" .
                "Ohne Zusatzrecht ändert ein Token nur unveröffentlichte Inhalte und Metadaten. Zusatzrechte:\n" .
                "  --allow-publish            Seiten, Inhaltselemente und Nachrichten sichtbar schalten\n" .
                "  --allow-live-edit          Titel und Texte sichtbarer Seiten, Elemente und Nachrichten ändern\n" .
                "  --allow-external-redirect  Umleitungen auf fremde Websites oder von der Startseite (/)\n\n" .
                'Gespeichert wird nur der SHA-256-Hash in sys_registry (Namensraum velundo_connect).'
            )
            ->addArgument('action', InputArgument::REQUIRED, 'create, list oder revoke')
            ->addArgument('value', InputArgument::OPTIONAL, 'Bezeichnung des Tokens (create) oder Kennung (revoke)')
            ->addOption('allow-publish', null, InputOption::VALUE_NONE, 'Zusatzrecht: Inhalte sichtbar schalten')
            ->addOption('allow-live-edit', null, InputOption::VALUE_NONE, 'Zusatzrecht: sichtbare Inhalte ändern')
            ->addOption('allow-external-redirect', null, InputOption::VALUE_NONE, 'Zusatzrecht: Umleitungen auf fremde Hosts oder von der Startseite');
    }

    /** Zusatzrechte aus den Optionen (--allow-publish → publish …). @return list<string> */
    private static function scopesFromInput(InputInterface $input): array
    {
        $scopes = [];
        foreach (TokenService::SCOPES as $scope) {
            if ($input->getOption('allow-' . str_replace('_', '-', $scope))) {
                $scopes[] = $scope;
            }
        }
        return $scopes;
    }

    /** @param list<string> $scopes */
    private static function scopesText(array $scopes): string
    {
        return $scopes === [] ? 'nur Entwürfe/Metadaten' : implode(', ', $scopes);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $action = strtolower((string)$input->getArgument('action'));
        $value = trim((string)$input->getArgument('value'));

        switch ($action) {
            case 'create':
                if ($value === '') {
                    $io->error('Bitte eine Bezeichnung angeben: velundo:token create "Agentur Test"');
                    return Command::INVALID;
                }
                try {
                    $created = $this->tokens->create($value, self::scopesFromInput($input));
                } catch (\InvalidArgumentException $e) {
                    $io->error($e->getMessage());
                    return Command::INVALID;
                }
                $io->success(sprintf('Token „%s“ (Kennung %s, Rechte: %s) wurde erzeugt. Es wird nur dieses eine Mal angezeigt:', $created['name'], $created['id'], self::scopesText($created['scopes'])));
                $output->writeln($created['token']);
                $output->writeln('');
                $io->text('In der velundo-App als Zugangstoken der Verbindung eintragen. Endpunktbasis: https://<host>/_velundo/v1/');
                return Command::SUCCESS;

            case 'list':
                $rows = [];
                foreach ($this->tokens->list() as $token) {
                    $rows[] = [
                        $token['id'],
                        $token['name'],
                        date('Y-m-d H:i', $token['created']),
                        $token['last_used'] === null ? '–' : date('Y-m-d H:i', $token['last_used']),
                        $token['revoked'] === null ? 'aktiv' : 'widerrufen ' . date('Y-m-d H:i', $token['revoked']),
                        self::scopesText($token['scopes'] ?? []),
                    ];
                }
                if ($rows === []) {
                    $io->note('Noch kein Token vorhanden. Erzeugen mit: velundo:token create "Bezeichnung"');
                    return Command::SUCCESS;
                }
                $table = new Table($output);
                $table->setHeaders(['Kennung', 'Bezeichnung', 'Erzeugt', 'Zuletzt verwendet', 'Status', 'Rechte'])->setRows($rows);
                $table->render();
                return Command::SUCCESS;

            case 'revoke':
                if ($value === '') {
                    $io->error('Bitte die Kennung angeben (siehe velundo:token list).');
                    return Command::INVALID;
                }
                if (!$this->tokens->revoke($value)) {
                    $io->error(sprintf('Kein aktives Token mit der Kennung „%s“.', $value));
                    return Command::FAILURE;
                }
                $io->success(sprintf('Token „%s“ wurde widerrufen.', $value));
                return Command::SUCCESS;

            default:
                $io->error('Unbekannte Aktion. Erlaubt sind create, list und revoke.');
                return Command::INVALID;
        }
    }
}
