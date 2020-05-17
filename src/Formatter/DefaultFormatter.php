<?php
declare(strict_types=1);

namespace Bartlett\GraphPlantUml\Formatter;

use Bartlett\GraphUml\Formatter\AbstractFormatter;
use Bartlett\GraphUml\Formatter\FormatterInterface;

use Exception;
use ReflectionClass;
use ReflectionExtension;
use ReflectionMethod;
use ReflectionParameter;
use function str_repeat;
use function str_replace;

class DefaultFormatter extends AbstractFormatter implements FormatterInterface
{
    public function getLabelExtension(ReflectionExtension $reflection): string
    {
        $constants = $this->getLabelConstants($reflection);
        $operations = $this->getLabelFunctions($reflection->getFunctions());

        return $reflection->getName() . '<<(E,#FF7700) Extension >>'
            . $constants
            . $operations
        ;
    }

    public function getLabelClass(ReflectionClass $reflection): string
    {
        $constants = $this->getLabelConstants($reflection);
        $fields = $this->getLabelProperties($reflection);
        $operations = $this->getLabelFunctions($reflection->getMethods(), $reflection->getName());

        $indent = str_repeat($this->options['indent_string'], 2);

        $label = '';
        if (!empty($constants)) {
            $label .= $constants . self::EOL;
        }
        if (!empty($fields)) {
            $label .= $indent . $fields . self::EOL;
        }
        $label .= $indent . '--' . self::EOL;
        if (!empty($operations)) {
            $label .= $indent . $operations . self::EOL;
        }

        return $label;
    }

    public function getLabelConstants($reflection): string
    {
        if (!$this->options['show_constants']) {
            return '';
        }

        $indent = str_repeat($this->options['indent_string'], 2);
        $label = '';
        $parent = ($reflection instanceof ReflectionClass) ? $reflection->getParentClass() : false;

        foreach ($reflection->getConstants() as $name => $value) {
            if ($this->options['only_self'] && $parent && $parent->getConstant($name) === $value) {
                continue;
            }

            $label .= $indent . '+{static} '
                . $this->escape($name)
                . ' : '
                . $this->escape($this->getType(gettype($value)))
                . ' = '
                . $this->getCasted($value)
                . ' {readOnly}'
            ;
            $label .= self::EOL;
        }

        return $label;
    }

    public function getLabelProperties(ReflectionClass $reflection): string
    {
        if (!$this->options['show_properties']) {
            return '';
        }

        $properties = $reflection->getProperties();

        if (count($properties) === 0) {
            return '';
        }

        $defaults = $reflection->getDefaultProperties();
        $fields = [];

        foreach ($properties as $property) {
            if ($this->options['only_self'] && $property->getDeclaringClass()->getName() !== $reflection->getName()) {
                continue;
            }

            if (!$this->isVisible($property)) {
                continue;
            }

            $label = $this->visibility($property);
            if ($property->isStatic()) {
                $label .= '{static} ';
            }
            $label .= $this->escape($property->getName());

            if (method_exists($property, 'hasType')) {
                // introduces with PHP 7.4
                // @link https://www.php.net/manual/en/reflectionproperty.hastype.php
                if ($property->hasType()) {
                    $type = $property->getType()->getName();
                }
            } else {
                // fallback to phpdoc annotations
                $type = $this->getDocBlockVar($property);
            }

            if ($type !== null) {
                $label .= ' : ' . $this->escape($type);
            }

            // only show non-NULL values
            if (isset($defaults[$property->getName()])) {
                $label .= ' = ' . $this->getCasted($defaults[$property->getName()]);
            }
            $fields[] = $label;
        }

        if (empty($fields)) {
            return '';
        }

        return implode(self::EOL . str_repeat($this->options['indent_string'], 2), $fields);
    }

    public function getLabelFunctions(array $functions, string $class = null): string
    {
        if ($class && !$this->options['show_methods']) {
            return '';
        }

        $operations = [];

        foreach ($functions as $method) {
            $label = '';
            if ($method instanceof ReflectionMethod) {
                // method not defined in this class (inherited from parent), so skip
                if ($this->options['only_self'] && $method->getDeclaringClass()->getName() !== $class) {
                    continue;
                }

                if (!$this->isVisible($method)) {
                    continue;
                }

                $label .= $this->visibility($method);

                if ($method->isAbstract()) {
                    $label .= '{abstract}';
                }
                if ($method->isStatic()) {
                    $label .= '{static}';
                }
            } else {
                // ReflectionFunction does not define any of the above accessors
                // simply pretend this is a "normal" public method
                $label .= '+';
            }
            $label .= $this->escape($method->getName()) . '(';

            $firstParam = true;
            foreach ($method->getParameters() as $parameter) {
                /** @var $parameter ReflectionParameter */
                if ($firstParam) {
                    $firstParam = false;
                } else {
                    $label .= ', ';
                }

                if ($parameter->isPassedByReference()) {
                    $label .= 'inout ';
                }

                $label .= $this->escape($parameter->getName());

                $type = $this->getParameterType($parameter);
                if ($type !== null) {
                    $label .= ' : ' . $this->escapeNamespaceSeparator($type);
                }

                if ($parameter->isOptional()) {
                    try {
                        $label .= ' = ' . $this->getCasted(
                                str_replace(['self::', 'static::'], '', $parameter->getDefaultValueConstantName()),
                                ''
                            );
                    } catch (Exception $ignore) {
                        $label .= ' = «unknown»';
                    }
                }
            }
            $label .= ')';

            $returnType = $method->getReturnType();
            if ($returnType) {
                $type = (string) $returnType;
                if ($type !== null) {
                    $label .= ' : ' . ($returnType->allowsNull() ? '?' : '') . $this->escapeNamespaceSeparator($type);
                }
            }

            $operations[] = $label;
        }

        if (empty($operations)) {
            return '';
        }

        return implode(self::EOL . str_repeat($this->options['indent_string'], 2), $operations);
    }

    private function escapeNamespaceSeparator(string $namespace): string
    {
        return str_replace('\\', $this->options['namespace_separator'], $namespace);
    }
}
