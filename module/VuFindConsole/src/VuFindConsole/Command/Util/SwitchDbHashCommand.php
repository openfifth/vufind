<?php

/**
 * Console command: switch database encryption algorithm.
 *
 * PHP version 8
 *
 * Copyright (C) Villanova University 2020.
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License version 2,
 * as published by the Free Software Foundation.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, write to the Free Software
 * Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA  02110-1301  USA
 *
 * @category VuFind
 * @package  Console
 * @author   Demian Katz <demian.katz@villanova.edu>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://vufind.org/wiki/development Wiki
 */

namespace VuFindConsole\Command\Util;

use Closure;
use InvalidArgumentException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use VuFind\Config\Config;
use VuFind\Config\PathResolver;
use VuFind\Config\Writer as ConfigWriter;
use VuFind\Crypt\BlockCipher;
use VuFind\Db\Entity\UserCardEntityInterface;
use VuFind\Db\Entity\UserEntityInterface;
use VuFind\Db\Service\DbServiceInterface;
use VuFind\Db\Service\UserCardServiceInterface;
use VuFind\Db\Service\UserServiceInterface;

use function count;

/**
 * Console command: switch database encryption algorithm.
 *
 * @category VuFind
 * @package  Console
 * @author   Demian Katz <demian.katz@villanova.edu>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://vufind.org/wiki/development Wiki
 */
#[AsCommand(
    name: 'util/switch_db_hash',
    description: 'Encryption algorithm switcher'
)]
class SwitchDbHashCommand extends Command
{
    /**
     * Constructor
     *
     * @param Config                   $config          VuFind configuration
     * @param UserServiceInterface     $userService     User database service
     * @param UserCardServiceInterface $userCardService UserCard database service
     * @param Closure                  $cipherFactory   Callback to generate a BlockCipher object (must
     * take two arguments: algorithm and key)
     * @param PathResolver             $pathResolver    Config file path resolver
     * @param ?string                  $name            The name of the command; passing null means
     * it must be set in configure()
     */
    public function __construct(
        protected Config $config,
        protected UserServiceInterface $userService,
        protected UserCardServiceInterface $userCardService,
        protected Closure $cipherFactory,
        protected PathResolver $pathResolver,
        ?string $name = null,
    ) {
        parent::__construct($name);
    }

    /**
     * Configure the command.
     *
     * @return void
     */
    protected function configure()
    {
        $this
            ->setHelp(
                'Switches the encryption algorithm in the database '
                . 'and config. Expects new algorithm and (optional) new key as'
                . ' parameters.'
            )->addArgument('newmethod', InputArgument::REQUIRED, 'Encryption method')
            ->addArgument('newkey', InputArgument::OPTIONAL, 'Encryption key');
    }

    /**
     * Get a config writer
     *
     * @param string $path Path of file to write
     *
     * @return ConfigWriter
     */
    protected function getConfigWriter($path)
    {
        return new ConfigWriter($path);
    }

    /**
     * Re-encrypt an entity.
     *
     * @param AbstractDbService                           $service   Database service
     * @param UserEntityInterface|UserCardEntityInterface $entity    Row to update
     * @param ?BlockCipher                                $oldcipher Old cipher (null for none)
     * @param BlockCipher                                 $newcipher New cipher
     *
     * @return void
     * @throws InvalidArgumentException
     */
    protected function fixEntity(
        DbServiceInterface $service,
        UserEntityInterface|UserCardEntityInterface $entity,
        ?BlockCipher $oldcipher,
        BlockCipher $newcipher
    ): void {
        $oldEncrypted = $entity->getCatPassEnc();
        $pass = ($oldcipher && $oldEncrypted !== null)
            ? $oldcipher->decrypt($oldEncrypted)
            : $entity->getRawCatPassword();
        $entity->setRawCatPassword(null);
        $entity->setCatPassEnc($pass === null ? null : $newcipher->encrypt($pass));
        $service->persistEntity($entity);
    }

    /**
     * Run the command.
     *
     * @param InputInterface  $input  Input object
     * @param OutputInterface $output Output object
     *
     * @return int 0 for success
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        // Validate command line arguments:
        $newhash = $input->getArgument('newmethod');

        // Pull existing encryption settings from the configuration:
        if (
            !isset($this->config->Authentication->ils_encryption_key)
            || !($this->config->Authentication->encrypt_ils_password ?? false)
        ) {
            $oldhash = 'none';
            $oldkey = null;
        } else {
            $oldhash = $this->config->Authentication->ils_encryption_algo
                ?? 'blowfish';
            $oldkey = $this->config->Authentication->ils_encryption_key;
        }

        // Pull new encryption settings from argument or config:
        $newkey = $input->getArgument('newkey') ?? $oldkey;

        // No key specified AND no key on file = fatal error:
        if ($newkey === null) {
            $output->writeln('Please specify a key as the second parameter.');
            return 1;
        }

        // If no changes were requested, abort early:
        if ($oldkey == $newkey && $oldhash == $newhash) {
            $output->writeln('No changes requested -- no action needed.');
            return 0;
        }

        // Initialize ciphers first, so we can catch any illegal algorithms before making any changes:
        try {
            $oldcipher = ($oldhash === 'none') ? null : ($this->cipherFactory)($oldhash, $oldkey);
            $newcipher = ($this->cipherFactory)($newhash, $newkey);
        } catch (\Exception $e) {
            $output->writeln($e->getMessage());
            return 1;
        }

        // Next update the config file, so if we are unable to write the file,
        // we don't go ahead and make unwanted changes to the database:
        $configPath = $this->pathResolver->getLocalConfigPath('config.ini', null, true);
        $output->writeln("\tUpdating $configPath...");
        $writer = $this->getConfigWriter($configPath);
        $writer->set('Authentication', 'encrypt_ils_password', true);
        $writer->set('Authentication', 'ils_encryption_algo', $newhash);
        $writer->set('Authentication', 'ils_encryption_key', $newkey);
        if (!$writer->save()) {
            $output->writeln("\tWrite failed!");
            return 1;
        }

        // Now do the database rewrite:
        $users = $this->userService->getAllUsersWithCatUsernames();
        $cards = $this->userCardService->getAllRowsWithUsernames();
        $output->writeln("\tConverting hashes for " . count($users) . ' user(s).');
        foreach ($users as $row) {
            try {
                $this->fixEntity($this->userService, $row, $oldcipher, $newcipher);
            } catch (\Exception $e) {
                $output->writeln("Problem with user {$row->getUsername()}: " . (string)$e);
            }
        }
        if (count($cards) > 0) {
            $output->writeln("\tConverting hashes for " . count($cards) . ' card(s).');
            foreach ($cards as $entity) {
                try {
                    $this->fixEntity($this->userCardService, $entity, $oldcipher, $newcipher);
                } catch (\Exception $e) {
                    $output->writeln("Problem with card {$entity->getId()}: " . (string)$e);
                }
            }
        }

        // If we got this far, all went well!
        $output->writeln("\tFinished.");
        return 0;
    }
}
