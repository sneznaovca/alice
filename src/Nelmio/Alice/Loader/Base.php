<?php

/*
 * This file is part of the Alice package.
 *
 * (c) Nelmio <hello@nelm.io>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Nelmio\Alice\Loader;

use Symfony\Component\Form\Util\FormUtil;
use Nelmio\Alice\ORMInterface;
use Nelmio\Alice\LoaderInterface;

/**
 * Loads fixtures from an array or php file
 *
 * The php code if $data is a file has access to $loader->fake() to
 * generate data and must return an array of the format below.
 *
 * The array format must follow this example:
 *
 *     array(
 *         'Namespace\Class' => array(
 *             'name' => array(
 *                 'property' => 'value',
 *                 'property2' => 'value',
 *             ),
 *             'name2' => array(
 *                 [...]
 *             ),
 *         ),
 *     )
 */
class Base implements LoaderInterface
{
    protected $references = array();

    /**
     * @var ORMInterface
     */
    protected $manager;

    /**
     * @var \Faker\Generator[]
     */
    private $generators;

    /**
     * Default locale to use with faker
     *
     * @var string
     */
    private $defaultLocale;

    /**
     * Custom faker providers to use with faker generator
     *
     * @var array
     */
    private $providers;

    /**
     * @var int
     */
    private $currentRangeId;

    /**
     * @param string $locale default locale to use with faker if none is
     *      specified in the expression
     * @param array $providers custom faker providers in addition to the default
     *      ones from faker
     * @param int $seed a seed to make sure faker generates data consistently across
     *      runs, set to null to disable
     */
    public function __construct($locale = 'en_US', array $providers = array(), $seed = 1)
    {
        $this->defaultLocale = $locale;
        $this->providers = $providers;

        if (is_numeric($seed)) {
            mt_srand($seed);
        }
    }

    /**
     * {@inheritDoc}
     */
    public function load($data)
    {
        if (!is_array($data)) {
            // $loader is defined to give access to $loader->fake() in the included file's context
            $loader = $this;
            $includeWrapper = function () use ($data, $loader) {
                return include $data;
            };
            $data = $includeWrapper();
            if (!is_array($data)) {
                throw new \UnexpectedValueException('Included PHP files must return an array of data');
            }
        }

        $objects = array();

        foreach ($data as $class => $instances) {
            foreach ($instances as $name => $spec) {
                if (preg_match('#\{([0-9]+)\.\.(\.?)([0-9]+)\}#i', $name, $match)) {
                    $from = $match[1];
                    $to = empty($match[2]) ? $match[3] : $match[3] - 1;
                    if ($from > $to) {
                        list($to, $from) = array($from, $to);
                    }
                    for ($i = $from; $i <= $to; $i++) {
                        $this->currentRangeId = $i;
                        $objects[] = $this->createObject($class, str_replace($match[0], $i, $name), $spec);
                    }
                    $this->currentRangeId = null;
                } else {
                    $objects[] = $this->createObject($class, $name, $spec);
                }
            }
        }

        return $objects;
    }

    /**
     * {@inheritDoc}
     */
    public function getReference($name, $property = null)
    {
        if (isset($this->references[$name])) {
            $reference = $this->references[$name];

            if ($property !== null) {
                if (property_exists($reference, $property)) {
                    $prop = new \ReflectionProperty($reference, $property);

                    if ($prop->isPublic()) {
                        return $reference->{$property};
                    }
                }

                $getter = 'get'.ucfirst($property);
                if (method_exists($reference, $getter) && is_callable(array($reference, $getter))) {
                    return $reference->$getter();
                }

                throw new \UnexpectedValueException('Property '.$property.' is not defined for reference '.$name);
            }

            return $this->references[$name];
        }

        throw new \UnexpectedValueException('Reference '.$name.' is not defined');
    }

    /**
     * {@inheritDoc}
     */
    public function getReferences()
    {
        return $this->references;
    }

    public function fake($formatter, $locale = null, $arg = null, $arg2 = null, $arg3 = null)
    {
        $args = func_get_args();
        array_shift($args);
        array_shift($args);

        if ($formatter == 'current') {
            if ($this->currentRangeId === null) {
                throw new \UnexpectedValueException('Cannot use <current()> out of fixtures ranges.');
            }

            return $this->currentRangeId;
        }

        return $this->getGenerator($locale)->format($formatter, $args);
    }

    /**
     * Get the generator for this locale
     *
     * @param string $locale the requested locale, defaults to constructor injected default
     *
     * @return \Faker\Generator the generator for the requested locale
     */
    private function getGenerator($locale = null)
    {
        $locale = $locale ?: $this->defaultLocale;

        if (!isset($this->generators[$locale])) {
            $generator = \Faker\Factory::create($locale);
            foreach ($this->providers as $provider) {
                $generator->addProvider($provider);
            }
            $this->generators[$locale] = $generator;
        }

        return $this->generators[$locale];
    }

    private function createObject($class, $name, $data)
    {
        $obj = unserialize(sprintf('O:%d:"%s":0:{}', strlen($class), $class));
        $variables = array();
        foreach ($data as $key => $val) {
            if (is_array($val) && '{' === key($val)) {
                throw new \RuntimeException('Misformatted string in object '.$name.', '.$key.'\'s value should be quoted if you used yaml.');
            }

            // process values
            $val = $this->process($val, $variables);

            // add relations if available
            if (is_array($val) && $method = $this->findAdderMethod($obj, $key)) {
                foreach ($val as $rel) {
                    $rel = $this->checkTypeHints($obj, $method, $rel);
                    $obj->{$method}($rel);
                }
            } elseif (method_exists($obj, 'set'.$key)) {
                $val = $this->checkTypeHints($obj, 'set'.$key, $val);
                $obj->{'set'.$key}($val);
                $variables[$key] = $val;
            } elseif (property_exists($obj, $key)) {
                $refl = new \ReflectionProperty($obj, $key);
                $refl->setAccessible(true);
                $refl->setValue($obj, $val);

                $variables[$key] = $val;
            } else {
                throw new \UnexpectedValueException('Could not determine how to assign '.$key.' to a '.$class.' object.');
            }
        }

        return $this->references[$name] = $obj;
    }

    /**
     * Checks if the value is typehinted with a class and if the current value can be coerced into that type
     *
     * It can either convert to datetime or attempt to fetched from the db by id
     *
     * @param  object $obj
     * @param  string $method
     * @param  string $value
     * @return mixed
     */
    private function checkTypeHints($obj, $method, $value)
    {
        if (!is_numeric($value) && !is_string($value)) {
            return $value;
        }

        $reflection = new \ReflectionMethod(get_class($obj), $method);
        $params = $reflection->getParameters();

        if (!$params[0]->getClass()) {
            return $value;
        }

        $hintedClass = $params[0]->getClass()->getName();

        if ($hintedClass === 'DateTime') {
            try {
                if (preg_match('{^[0-9]+$}', $value)) {
                    $value = '@'.$value;
                }

                return new \DateTime($value);
            } catch (\Exception $e) {
                throw new \UnexpectedValueException('Could not convert '.$value.' to DateTime for '.get_class($obj).'::'.$method, 0, $e);
            }
        }

        if (is_numeric($value)) {
            if (!$this->manager) {
                throw new \LogicException('To reference objects by id you must first set a Nelmio\Alice\ORMInterface object on this instance');
            }
            $value = $this->manager->find($hintedClass, $value);
        }

        return $value;
    }

    private function process($data, array $variables)
    {
        if (is_array($data)) {
            foreach ($data as $key => $val) {
                $data[$key] = $this->process($val, $variables);
            }

            return $data;
        }

        // check for conditional values (20%? true : false)
        if (is_string($data) && preg_match('{^(?<threshold>[0-9.]+%?)\? (?<true>.+?)(?: : (?<false>.+?))?$}', $data, $match)) {
            // process true val since it's always needed
            $trueVal = $this->process($match['true'], $variables);

            // compute threshold and check if we are beyond it
            $threshold = $match['threshold'];
            if (substr($threshold, -1) === '%') {
                $threshold = substr($threshold, 0, -1) / 100;
            }
            $randVal = mt_rand(0, 100) / 100;
            if ($threshold > 0 && $randVal <= $threshold) {
                return $trueVal;
            } else {
                $emptyVal = is_array($trueVal) ? array() : null;

                if (isset($match['false']) && '' !== $match['false']) {
                    return $this->process($match['false'], $variables);
                }

                return $emptyVal;
            }
        }

        // return non-string values
        if (!is_string($data)) {
            return $data;
        }

        $that = $this;
        // replaces a placeholder by the result of a ->fake call
        $replacePlaceholder = function ($matches) use ($variables, $that) {
            $args = isset($matches['args']) && '' !== $matches['args'] ? $matches['args'] : null;

            if (!$args) {
                return $that->fake($matches['name'], $matches['locale']);
            }

            // replace references to other variables in the same object
            $args = preg_replace_callback('{\{?\$([a-z0-9_]+)\}?}i', function ($match) use ($variables) {
                if (isset($variables[$match[1]])) {
                    return '$variables['.var_export($match[1], true).']';
                }

                return $match[0];
            }, $args);

            $locale = var_export($matches['locale'], true);
            $name = var_export($matches['name'], true);

            return eval('return $that->fake(' . $name . ', ' . $locale . ', ' . $args . ');');
        };

        // format placeholders without preg_replace if there is only one to avoid __toString() being called
        $placeHolderRegex = '<(?:(?<locale>[a-z]+(?:_[a-z]+)?):)?(?<name>[a-z0-9_]+?)\((?<args>(?:[^)]*|\)(?!>))*)\)>';
        if (preg_match('#^'.$placeHolderRegex.'$#i', $data, $matches)) {
            $data = $replacePlaceholder($matches);
        } else {
            // format placeholders inline
            $data = preg_replace_callback('#'.$placeHolderRegex.'#i', function ($matches) use ($replacePlaceholder) {
                return $replacePlaceholder($matches);
            }, $data);
        }

        // process references
        if (is_string($data) && preg_match('{^(?:(?<multi>\d+)x )?@(?<reference>[a-z0-9_.*-]+)(?:\->(?<property>[a-z0-9_-]+))?$}i', $data, $matches)) {
            if (strpos($matches['reference'], '*')) {
                $data = $this->getRandomReferences($matches['reference'], ('' !== $matches['multi']) ? $matches['multi'] : null);
            } else {
                if ('' !== $matches['multi']) {
                    throw new \UnexpectedValueException('To use multiple references you must use a mask like "'.$matches['multi'].'x @user*", otherwise you would always get only one item.');
                }
                $data = $this->getReference($matches['reference'], isset($matches['property']) ? $matches['property'] : null);
            }
        }

        return $data;
    }

    private function getRandomReferences($mask, $count = 1)
    {
        if ($count === 0) {
            return array();
        }

        $availableRefs = array();
        foreach ($this->references as $key => $val) {
            if (preg_match('{^'.str_replace('*', '.+', $mask).'$}', $key)) {
                $availableRefs[] = $val;
            }
        }

        if (!$availableRefs) {
            throw new \UnexpectedValueException('Reference mask "'.$mask.'" did not match any existing reference, make sure the object is created after its references');
        }

        if (null === $count) {
            return $availableRefs[mt_rand(0, count($availableRefs) - 1)];
        }

        $res = array();
        while ($count-- && $availableRefs) {
            $ref = array_splice($availableRefs, mt_rand(0, count($availableRefs) - 1), 1);
            $res[] = current($ref);
        }

        return $res;
    }

    private function findAdderMethod($obj, $key)
    {
        if (method_exists($obj, $method = 'add'.$key)) {
            return $method;
        }

        if (class_exists('Symfony\Component\Form\Util\FormUtil') && method_exists('Symfony\Component\Form\Util\FormUtil', 'singularify')) {
            foreach ((array) FormUtil::singularify($key) as $singularForm) {
                if (method_exists($obj, $method = 'add'.$singularForm)) {
                    return $method;
                }
            }
        }

        if (method_exists($obj, $method = 'add'.rtrim($key, 's'))) {
            return $method;
        }

        if (substr($key, -3) === 'ies' && method_exists($obj, $method = 'add'.substr($key, 0, -3).'y')) {
            return $method;
        }
    }

    public function setORM(ORMInterface $manager)
    {
        $this->manager = $manager;
    }
}
