<?php
declare(strict_types=1);
/**
 * @copyright Copyright (c) 2019 Joas Schilling <coding@schilljs.com>
 *
 * @license GNU AGPL version 3 or any later version
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 */

namespace OCA\Spreed\Chat\Command;


use OCA\Spreed\Model\Command;
use OCA\Spreed\Room;
use OCP\Comments\IComment;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\EventDispatcher\GenericEvent;

class Executor {

	public const PLACEHOLDER_ROOM = '{ROOM}';
	public const PLACEHOLDER_USER = '{USER}';
	public const PLACEHOLDER_ARGUMENTS = '{ARGUMENTS}';
	public const PLACEHOLDER_ARGUMENTS_DOUBLEQUOTE_ESCAPED = '{ARGUMENTS_DOUBLEQUOTE_ESCAPED}';

	/** @var EventDispatcherInterface */
	protected $dispatcher;

	public function __construct(EventDispatcherInterface $dispatcher) {
		$this->dispatcher = $dispatcher;
	}

	public function exec(Room $room, IComment $message, Command $command, string $arguments): void {
		if ($command->getApp() === '' && $command->getCommand() === 'help') {
			$output = $this->execHelp($room, $message, $command, $arguments);
		} else if ($command->getApp() !== '') {
			$output = $this->execApp($room, $message, $command, $arguments);
		} else  {
			$output = $this->execShell($room, $message, $command, $arguments);
		}

		$user = $message->getActorType() === 'users' ? $message->getActorId() : '';
		$message->setMessage(json_encode([
			'user' => $user,
			'visibility' => $command->getResponse(),
			'output' => $output,
		]));
		$message->setActor('bots', $command->getName());
		$message->setVerb('command');
	}

	protected function execHelp(Room $room, IComment $message, Command $command, string $arguments): string {
		// FIXME Implement a useful help
		return $arguments;
	}

	protected function execApp(Room $room, IComment $message, Command $command, string $arguments): string {
		$event = $this->createEvent($command);
		$event->setArguments([
			'room' => $room,
			'message' => $message,
			'arguments' => $arguments,
			'output' => '',
		]);

		$this->dispatcher->dispatch(self::class . '::execApp', $event);

		return (string) $event->getArgument('output');
	}

	protected function createEvent(Command $command): GenericEvent {
		return new GenericEvent($command);
	}

	protected function execShell(Room $room, IComment $message, Command $command, string $arguments): string {
		$user = $message->getActorType() === 'users' ? $message->getActorId() : '';

		$cmd = str_replace([
			self::PLACEHOLDER_ROOM,
			self::PLACEHOLDER_USER,
			self::PLACEHOLDER_ARGUMENTS,
			self::PLACEHOLDER_ARGUMENTS_DOUBLEQUOTE_ESCAPED,
		], [
			escapeshellarg($room->getToken()),
			escapeshellarg($user),
			$this->escapeArguments($arguments),
			str_replace('"', '\\"', $arguments),
		], $command->getScript());

		return $this->wrapExec($cmd);
	}

	protected function escapeArguments(string $argumentString): string {
		$arguments = explode(' ', $argumentString);

		$result = [];
		$buffer = [];
		$quote = '';
		foreach ($arguments as $argument) {
			if ($quote === '') {
				if (ltrim($argument, '"\'') === $argument) {
					$result[] = escapeshellarg($argument);
				} else {
					$quote = $argument[0];
					$temp = substr($argument, 1);
					if (rtrim($temp, $quote) === $temp) {
						$buffer[] = $temp;
					} else {
						$result[] = $quote . str_replace($quote, '\\'. $quote, substr($temp, 0, -1)) . $quote;
						$quote = '';
					}
				}
			} else if (rtrim($argument, $quote) === $argument) {
				$buffer[] = $argument;
			} else {
				$buffer[] = substr($argument, 0, -1);

				$result[] = $quote . str_replace($quote, '\\'. $quote, implode(' ', $buffer)) . $quote;
				$quote = '';
				$buffer = [];
			}
		}

		if ($quote !== '') {
			$result[] = escapeshellarg($quote . implode(' ', $buffer));
		}

		return implode(' ', $result);
	}

	protected function wrapExec(string $cmd): string {
		$output = [];
		exec($cmd, $output);
		return implode("\n", $output);
	}
}
