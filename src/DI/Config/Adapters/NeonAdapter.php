<?php

/**
 * This file is part of the Nette Framework (https://nette.org)
 * Copyright (c) 2004 David Grudl (https://davidgrudl.com)
 */

declare(strict_types=1);

namespace Nette\DI\Config\Adapters;

use Nette;
use Nette\DI\Config\Helpers;
use Nette\DI\Statement;
use Nette\Neon;


/**
 * Reading and generating NEON files.
 */
final class NeonAdapter implements Nette\DI\Config\IAdapter
{
	use Nette\SmartObject;

	private const PREVENT_MERGING = '!';


	/**
	 * Reads configuration from NEON file.
	 */
	public function load(string $file): array
	{
		return $this->process((array) Neon\Neon::decode(file_get_contents($file)));
	}


	/**
	 * @throws Nette\InvalidStateException
	 */
	public function process(array $arr): array
	{
		$res = [];
		foreach ($arr as $key => $val) {
			if (is_string($key) && substr($key, -1) === self::PREVENT_MERGING) {
				if (!is_array($val) && $val !== null) {
					throw new Nette\InvalidStateException("Replacing operator is available only for arrays, item '$key' is not array.");
				}
				$key = substr($key, 0, -1);
				$val[Helpers::EXTENDS_KEY] = Helpers::OVERWRITE;
			}

			if (is_array($val)) {
				$val = $this->process($val);

			} elseif ($val instanceof Neon\Entity) {
				if ($val->value === Neon\Neon::CHAIN) {
					$tmp = null;
					foreach ($this->process($val->attributes) as $st) {
						$tmp = new Statement(
							$tmp === null ? $st->getEntity() : [$tmp, ltrim($st->getEntity(), ':')],
							$st->arguments
						);
					}
					$val = $tmp;
				} else {
					$tmp = $this->process([$val->value]);
					if (is_string($tmp[0]) && strpos($tmp[0], '?') !== false) {
						trigger_error('Operator ? is deprecated in config files.', E_USER_DEPRECATED);
					}
					$val = new Statement($tmp[0], $this->process($val->attributes));
				}
			}
			$res[$key] = $val;
		}
		return $res;
	}


	/**
	 * Generates configuration in NEON format.
	 */
	public function dump(array $data): string
	{
		array_walk_recursive(
			$data,
			function (&$val) {
				if ($val instanceof Statement) {
					$val = self::statementToEntity($val);
				}
			}
		);
		return "# generated by Nette\n\n" . Neon\Neon::encode($data, Neon\Neon::BLOCK);
	}


	private static function statementToEntity(Statement $val): Neon\Entity
	{
		array_walk_recursive(
			$val->arguments,
			function (&$val) {
				if ($val instanceof Statement) {
					$val = self::statementToEntity($val);
				}
			}
		);
		if (is_array($val->getEntity()) && $val->getEntity()[0] instanceof Statement) {
			return new Neon\Entity(
				Neon\Neon::CHAIN,
				[
					self::statementToEntity($val->getEntity()[0]),
					new Neon\Entity('::' . $val->getEntity()[1], $val->arguments),
				]
			);
		} else {
			return new Neon\Entity($val->getEntity(), $val->arguments);
		}
	}
}
