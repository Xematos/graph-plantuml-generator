<?php
declare(strict_types=1);

namespace Bartlett\GraphPlantUml;

use Bartlett\GraphPlantUml\Formatter\DefaultFormatter;
use Bartlett\GraphUml\Formatter\FormatterInterface;
use Bartlett\GraphUml\Generator\AbstractGenerator;
use Bartlett\GraphUml\Generator\GeneratorInterface;

use Graphp\Graph\EdgeDirected;
use Graphp\Graph\Entity;
use Graphp\Graph\Graph;
use Graphp\Graph\Vertex;

use function Jawira\PlantUml\encodep;

class PlantUmlGenerator extends AbstractGenerator implements GeneratorInterface
{
    private const EOL = PHP_EOL;

    public function __construct()
    {
        /**
         * Usually, your java executables should be located in your $PATH
         * environment variable and invoking a mere `java` is sufficient. If you
         * have no access to your $PATH variable, use this method to set the path
         * to your java runtime executable.
         *
         * This should contain '.exe' on windows.
         * - /full/path/to/bin/java
         * - java.exe
         * - c:\path\to\bin\java.exe
         */
        $this->setExecutable('java -jar plantuml.jar');
        // (invoke 'java -jar plantuml.jar -help' for details on available formats)
        $this->setFormat('png');
    }

    public function setOptions(array $values): void
    {
        $options = $values;

        // String to use as namespace separator (because PlantUML does not allow native PHP Namespace separator)
        if (!isset($options['namespace_separator'])) {
            $options['namespace_separator'] = '.';
        }

        parent::setOptions($options);
    }

    public function getFormatter(): FormatterInterface
    {
        return new DefaultFormatter($this->options);
    }

    public function getName(): string
    {
        return 'plantuml';
    }

    public function createScript(Graph $graph, bool $encode = false): string
    {
        // build an array to map vertex hashes to vertex IDs for output
        $groups = [];
        foreach ($graph->getVertices() as $vertex) {
            if ($vertex instanceof Vertex) {
                $groups[$vertex->getAttribute('group', 0)][] = $vertex;
            }
        }

        $script = ['@startuml'];

        $label = $this->getLayoutGraph($graph)['label'];
        if (!empty($label)) {
            $script[] = $label;
        }

        if (count($groups)) {
            // add subgraph cluster attributes
            $clusters = [
                'graph' => 'cluster.%s.graph.',
                'node'  => 'cluster.%s.node.',
                'edge'  => 'cluster.%s.edge.',
            ];
            $gid = 0;
            // put each group of vertices in a separate subgraph cluster
            foreach ($groups as $group => $vertices) {
                $prefix = $clusters['graph'];
                foreach ([$group, $gid] as $clusterId) {
                    $layout = $this->getAttributesPrefixed($graph, sprintf($prefix, $clusterId));
                    if (!empty($layout)) {
                        break;
                    }
                }
                $bgColor = ($layout['bgcolor'] ?? '');
                if (!empty($bgColor)) {
                    $bgColor = ' #' . ltrim($bgColor, "#");
                }
                $script[] = 'namespace ' . str_replace('\\', $this->options['namespace_separator'], $group) . $bgColor . ' {';
                foreach ($vertices as $vertex) {
                    $script[] = $this->getLayoutVertex($vertex)['label'] ?? '';
                }
                $script[] = '}';
                $gid++;
            }
        } else {
            foreach ($graph->getVertices() as $vertex) {
                $script[] = $this->getLayoutVertex($vertex)['label'] ?? '';
            }
        }

        // add all edges as directed edges
        foreach ($graph->getEdges() as $currentEdge) {
            $script[] = $this->getLayoutEdge($currentEdge)['label'] ?? '';
        }

        $script[] = '@enduml';
        $script[] = '';

        $str = implode(PHP_EOL, $script);
        if ($encode) {
            $str = encodep($str);
        }
        return $str;
    }

    public function createImageFile(Graph $graph, string $cmdFormat = ''): string
    {
        if (empty($cmdFormat)) {
            // default command format, when none provided
            $cmdFormat = sprintf(
                '%s -t%s %s -filename %s',
                self::CMD_EXECUTABLE,
                self::CMD_FORMAT,
                self::CMD_TEMP_FILE,
                self::CMD_OUTPUT_FILE
            );
        }

        return parent::createImageFile($graph, $cmdFormat);
    }

    private function getLayoutGraph(Graph $graph): array
    {
        $layout = $this->getAttributesPrefixed($graph, 'graph.');

        $layout['label'] = '';

        if (isset($layout['bgcolor'])) {
            $layout['bgcolor'] = ltrim($layout['bgcolor'], "#");
            $dashPrefix = strcasecmp($layout['bgcolor'], 'transparent') === 0 ? '' : '#';
            $layout['label'] .= self::EOL . 'skinparam backgroundColor ' . $dashPrefix . $layout['bgcolor'];
        }
        if (isset($layout['rankdir'])) {
            // @link https://graphviz.gitlab.io/_pages/doc/info/attrs.html#k:rankdir
            // BT and RL are not supported by PlantUML
            if (strcasecmp('LR', $layout['rankdir']) === 0) {
                $layout['label'] .= self::EOL . 'left to right direction';
            } elseif (strcasecmp('TB', $layout['rankdir']) === 0) {
                $layout['label'] .= self::EOL . 'top to bottom direction';
            }
        }

        return $layout;
    }

    private function getLayoutVertex(Vertex $vertex): array
    {
        $layout = $this->getAttributesPrefixed($vertex, '');

        $shortName = explode('\\', $vertex->getAttribute('id'));
        $shortName = array_pop($shortName);

        $stereotype = $vertex->getAttribute('stereotype', 'class');

        $indent = $this->options['indent_string'];

        $label = $indent
            . "$stereotype $shortName "
            . '<< ' . $stereotype . ' >> '
            . '{'
            . self::EOL
            . $layout['label_' . $this->getFormatter()->getFormat()]
            . $indent . '}'
        ;

        $layout['label'] = $label;

        return $layout;
    }

    private function getLayoutEdge(EdgeDirected $edge): array
    {
        $layout = $this->getAttributesPrefixed($edge, '');

        if ($layout['style'] === 'dashed') {
            // implementation
            $edgeop = '..|>';
        } else {
            // inheritance
            $edgeop = '--|>';
        }

        $layout['label'] = (str_replace('\\', '.', $edge->getVertexStart()->getAttribute('id')))
            . " $edgeop "
            . (str_replace('\\', '.', $edge->getVertexEnd()->getAttribute('id')))
        ;

        return $layout;
    }

    /**
     * @param Entity $entity
     * @param string $prefix
     * @return array
     */
    private function getAttributesPrefixed(Entity $entity, string $prefix): array
    {
        if (empty($prefix)) {
            $attributes = $entity->getAttributes();
        } else {
            $len = \strlen($prefix);
            $attributes = [];
            foreach ($entity->getAttributes() as $name => $value) {
                if (\strpos($name, $prefix) === 0) {
                    $attributes[substr($name, $len)] = $value;
                }
            }
        }
        return $attributes;
    }
}
