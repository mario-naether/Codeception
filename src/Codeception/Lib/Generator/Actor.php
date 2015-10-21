<?php
namespace Codeception\Lib\Generator;

use Codeception\Codecept;
use Codeception\Util\Template;

class Actor
{
    protected $template = <<<EOF
<?php //[STAMP] {{hash}}

// This class was automatically generated by build task
// You should not change it manually as it will be overwritten on next build
// @codingStandardsIgnoreFile

{{namespace}}
{{use}}

/**
 * Inherited Methods
{{inheritedMethods}}
 *
 * @SuppressWarnings(PHPMD)
*/
class {{guy}} extends \Codeception\Actor
{
   {{methods}}
}

EOF;

    protected $methodTemplate = <<<EOF

    /**
     * [!] Method is generated. Documentation taken from corresponding module.
     *
     {{doc}}
     * @see \{{module}}::{{method}}()
     */
    public function {{action}}({{params}}) {
        return \$this->scenario->runStep(new \Codeception\Step\{{step}}('{{method}}', func_get_args()));
    }
EOF;

    protected $inheritedMethodTemplate = ' * @method void {{method}}({{params}})';

    protected $settings;
    protected $modules;
    protected $actions;
    protected $numMethods = 0;

    public function __construct($settings)
    {
        $this->settings = $settings;
        $this->modules = \Codeception\Configuration::modules($settings);
        $this->actions = \Codeception\Configuration::actions($this->modules);
    }

    public function produce()
    {
        $namespace = rtrim($this->settings['namespace'], '\\');

        $uses = [];
        foreach ($this->modules as $module) {
            $uses[] = "use " . get_class($module) . ";";
        }

        $methods = [];
        $code = [];
        foreach ($this->actions as $action => $moduleName) {
            if (in_array($action, $methods)) {
                continue;
            }
            $class = new \ReflectionClass($this->modules[$moduleName]);
            $method = $class->getMethod($action);
            $code[] = $this->addMethod($method);
            $methods[] = $action;
            $this->numMethods++;
        }

        return (new Template($this->template))
            ->place('namespace', $namespace ? "namespace $namespace;" : '')
            ->place('hash', self::genHash($this->actions, $this->settings))
            ->place('use', implode("\n", $uses))
            ->place('guy', $this->settings['class_name'])
            ->place('methods', implode("\n\n ", $code))
            ->place('inheritedMethods', $this->prependAbstractGuyDocBlocks())
            ->produce();
    }

    public static function genHash($actions, $settings)
    {
        return md5(Codecept::VERSION.serialize($actions).serialize($settings['modules']));
    }

    protected function addMethod(\ReflectionMethod $refMethod)
    {
        $class = $refMethod->getDeclaringClass();
        $params = $this->getParamsString($refMethod);
        $module = $class->getName();

        $body = '';
        $doc = $this->addDoc($class, $refMethod);
        $doc = str_replace('/**', '', $doc);
        $doc = trim(str_replace('*/', '', $doc));
        if (!$doc) {
            $doc = "*";
        }

        $conditionalDoc = $doc . "\n     * Conditional Assertion: Test won't be stopped on fail";

        $methodTemplate = (new Template($this->methodTemplate))
            ->place('module', $module)
            ->place('method', $refMethod->name)
            ->place('params', $params);

        // generate conditional assertions
        if (0 === strpos($refMethod->name, 'see')) {
            $type = 'Assertion';
            $body .= $methodTemplate
                ->place('doc', $conditionalDoc)
                ->place('action', 'can' . ucfirst($refMethod->name))
                ->place('step', 'ConditionalAssertion')
                ->produce();

        // generate negative assertion
        } elseif (0 === strpos($refMethod->name, 'dontSee')) {
            $type = 'Assertion';
            $body .= $methodTemplate
                ->place('doc', $conditionalDoc)
                ->place('action', str_replace('dont', 'cant', $refMethod->name))
                ->place('step', 'ConditionalAssertion')
                ->produce();

        } elseif (0 === strpos($refMethod->name, 'am')) {
            $type = 'Condition';
        } else {
            $type = 'Action';
        }

        $body .= $methodTemplate
            ->place('doc', $doc)
            ->place('action', $refMethod->name)
            ->place('step', $type)
            ->produce();

        return $body;
    }

    /**
     * @param \ReflectionMethod $refMethod
     * @return array
     */
    protected function getParamsString(\ReflectionMethod $refMethod)
    {
        $params = array();
        foreach ($refMethod->getParameters() as $param) {
            if ($param->isOptional()) {
                $params[] = '$' . $param->name . ' = '.$this->getDefaultValue($param);
            } else {
                $params[] = '$' . $param->name;
            };

        }
        return implode(', ', $params);
    }

    /**
     * @param \ReflectionClass $class
     * @param \ReflectionMethod $refMethod
     * @return string
     */
    protected function addDoc(\ReflectionClass $class, \ReflectionMethod $refMethod)
    {
        $doc = $refMethod->getDocComment();

        if (!$doc) {
            $interfaces = $class->getInterfaces();
            foreach ($interfaces as $interface) {
                $i = new \ReflectionClass($interface->name);
                if ($i->hasMethod($refMethod->name)) {
                    $doc = $i->getMethod($refMethod->name)->getDocComment();
                    break;
                }
            }
        }

        if (!$doc and $class->getParentClass()) {
            $parent = new \ReflectionClass($class->getParentClass()->name);
            if ($parent->hasMethod($refMethod->name)) {
                $doc = $parent->getMethod($refMethod->name)->getDocComment();
                return $doc;
            }
            return $doc;
        }
        return $doc;
    }

    protected function prependAbstractGuyDocBlocks()
    {
        $inherited = array();

        $class = new \ReflectionClass('\Codeception\\Actor');
        $methods = $class->getMethods(\ReflectionMethod::IS_PUBLIC);

        foreach ($methods as $method) {
            if ($method->name == '__call') {
                continue;
            } // skipping magic
            if ($method->name == '__construct') {
                continue;
            } // skipping magic
            $params = $this->getParamsString($method);
            $inherited[] = (new Template($this->inheritedMethodTemplate))
                ->place('method', $method->name)
                ->place('params', $params)
                ->produce();
        }

        return implode("\n", $inherited);
    }

    public function getActorName()
    {
        return $this->settings['class_name'];
    }

    public function getModules()
    {
        return array_keys($this->modules);
    }

    public function getNumMethods()
    {
        return $this->numMethods;
    }

    private function getDefaultValue(\ReflectionParameter $param)
    {
        if ($param->isDefaultValueAvailable()) {
            if (method_exists($param, 'isDefaultValueConstant') && $param->isDefaultValueConstant()) {
                $constName = $param->getDefaultValueConstantName();
                if (false !== strpos($constName, '::')) {
                    list($class, $const) = explode('::', $constName);
                    if (in_array($class, ['self', 'static'])) {
                        $constName = $param->getDeclaringClass()->getName().'::'.$const;
                    }
                }

                return $constName;
            }

            return $this->phpEncodeValue($param->getDefaultValue());
        }

        return 'null';
    }

    /**
     * PHP encoded a value
     *
     * @param mixed $value
     *
     * @return string
     */
    private function phpEncodeValue($value)
    {
        if (is_array($value)) {
            return $this->phpEncodeArray($value);
        }

        if (is_string($value)) {
            return json_encode($value);
        }

        return var_export($value, true);
    }

    /**
     * Recursively PHP encode an array
     *
     * @param array $array
     *
     * @return string
     */
    private function phpEncodeArray(array $array)
    {
        $isPlainArray = function (array $value) {
            return ((count($value) === 0)
                || (
                    (array_keys($value) === range(0, count($value) - 1))
                    && (0 === count(array_filter(array_keys($value), 'is_string'))))
            );
        };

        if ($isPlainArray($array)) {
            return '['.implode(', ', array_map([$this, 'phpEncodeValue'], $array)).']';
        }

        return '['.implode(', ', array_map(function ($key) use ($array) {
            return $this->phpEncodeValue($key).' => '.$this->phpEncodeValue($array[$key]);
        }, array_keys($array))).']';
    }
}
