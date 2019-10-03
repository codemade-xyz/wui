<?php


namespace CodeMade\WuiBundle;


use CodeMade\WuiBundle\Liquid\Panel;
use CodeMade\WuiBundle\Liquid\Liquid;
use CodeMade\WuiBundle\Liquid\Template;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\KernelInterface;

class TemplateLiquid
{
    private $kernel;
    private $paths;
    private $defaultPath;
    private $settings;
    private $start_time;


    public function __construct(KernelInterface $kernel, array $settings = [])
    {
        $this->start_time = microtime(true);
        $this->settings = $settings;
        $this->kernel = $kernel;
        $this->paths = isset($this->settings['paths']) ? $this->settings['paths'] : [];
        $this->defaultPath = isset($this->settings['default_path']) ? $this->settings['default_path']: '';


    }

    public function addPath($name, $path)
    {
        $this->paths[$name] = $path;
    }


    /**
     * @param string $view
     * @param array $parameters
     * @return string
     * @throws \CodeMade\WuiBundle\Liquid\LiquidException
     */
    public function render(string $view, array $parameters)
    {
        if ($this->kernel->isDebug())
        {
            $panel = new Panel($this->kernel);
        }

        list($template_file, $template_path, $template_name, $view) = $this->getTemplateFile($view);

        Liquid::set('INCLUDE_SUFFIX', $this->settings['include_suffix']);
        Liquid::set('INCLUDE_PREFIX', $this->settings['include_prefix']);
        Liquid::setTemplate($template_name);
        Liquid::setView($view);
        Liquid::$project_dir = $this->kernel->getProjectDir();
        Liquid::$project_env = $this->kernel->getEnvironment();
        Liquid::setDB($this->kernel->getContainer()->get('database'));

        $request = $this->kernel->getContainer()->get('request_stack')->getCurrentRequest();


        $this->setSettingFromRequest($request);

        $cache = $this->getCacheSetting();

        $liquid = new Template($template_path.'/', $cache);


        if (!empty($this->settings['tags'])) {
            foreach ($this->settings['tags'] as $key => $item) {
                $liquid->registerTag($key, $item);
            }
        }

        if (!empty($this->settings['filter'])) {
            $liquid->registerFilter(new $this->settings['filter']);
        }

        $html = file_get_contents($template_file . '.' . $this->settings['include_suffix']);
        $liquid->parse($html);
        $content = $liquid->render($parameters);

        if ($this->kernel->isDebug() && !empty($panel))
        {

            if (is_array(Liquid::getError())) {
                throw new \LogicException('Liquid: '.Liquid::getError()[0]);
            }
            $content = $panel->render($liquid, $this->start_time, $content, $parameters);
        }

        if(isset($parameters['_error']) && $parameters['_error'] == '404') {
            $response = new \Symfony\Component\HttpFoundation\Response(
                $content,
                \Symfony\Component\HttpFoundation\Response::HTTP_NOT_FOUND,
                ['content-type' => 'text/html']
            );
            $response->send();
            $this->kernel->shutdown();
            exit();
        }

        return $content;

    }


    /**
     * @param Template $liquid
     * @param string $html
     * @param array $parameters
     * @return string
     * @throws \CodeMade\WuiBundle\Liquid\LiquidException
     */
    public function renderContent($liquid, $html, array $parameters)
    {
        if ($this->kernel->isDebug())
        {
            $panel = new Panel($this->kernel);
        }

        $liquid->parse($html);
        $content = $liquid->render($parameters);

        if ($this->kernel->isDebug() && !empty($panel))
        {

            if (is_array(Liquid::getError())) {
                throw new \LogicException('Liquid: '.Liquid::getError()[0]);
            }
            $content = $panel->render($liquid, $this->start_time, $content, $parameters);
        }

        if(isset($parameters['_error']) && $parameters['_error'] == '404') {
            $response = new \Symfony\Component\HttpFoundation\Response(
                $content,
                \Symfony\Component\HttpFoundation\Response::HTTP_NOT_FOUND,
                ['content-type' => 'text/html']
            );
            $response->send();
            $this->kernel->shutdown();
            exit();
        }

        return $content;

    }


    /**
     * @param string $template_path
     * @param string $template_name
     * @return string
     * @throws \CodeMade\WuiBundle\Liquid\LiquidException
     */
    public function getLiquid(string $template_path, string $template_name)
    {
        Liquid::set('INCLUDE_SUFFIX', $this->settings['include_suffix']);
        Liquid::set('INCLUDE_PREFIX', $this->settings['include_prefix']);
        Liquid::$project_dir = $this->kernel->getProjectDir();
        Liquid::$project_env = false;

        $request = $this->kernel->getContainer()->get('request_stack')->getCurrentRequest();


        $this->setSettingFromRequest($request);

        $cache = $this->getCacheSetting();

        $liquid = new Template($template_path.'/', $cache);


        if (!empty($this->settings['tags'])) {
            foreach ($this->settings['tags'] as $key => $item) {
                $liquid->registerTag($key, $item);
            }
        }

        if (!empty($this->settings['filter'])) {
            $liquid->registerFilter(new $this->settings['filter']);
        }


        return $liquid;

    }

    public function setSettingFromRequest(Request $request)
    {
        Liquid::setLocale($request->getLocale());
    }

    public function getTemplateFile($view)
    {
        $template_name = false;

        if (preg_match('/(^@[a-zA-Z]+)\//iu', $view, $match))
        {
            $template_name = isset($match[1]) ? str_replace('@', '', $match[1]) : null;
            $view = str_replace($match[0], '', $view);
        }

        if ($template_name && empty($this->paths[$template_name])) {
            throw new \LogicException('Path name "'.$template_name.'" not found in configuration file.');
        }

        $template_path = isset($this->paths[$template_name]) ? $this->paths[$template_name] : $this->defaultPath;

        //$template_path .= '/templates';

        $template_file = $template_path . '/templates/' . $view;
        if (!file_exists($template_file . '.' . $this->settings['include_suffix'])) {
            throw new \LogicException('File template "'.$template_file . '.' . $this->settings['include_suffix'].'" not found.');
        }

        return [
            $template_file,
            $template_path,
            $template_name,
            $view
        ];
    }

    private function getCacheSetting()
    {
        if (isset($this->settings['cache']) && $this->settings['cache']) {
            if (!is_dir($this->settings['cache'])) {
                if (!mkdir($this->settings['cache'], 0777, true)) {
                    throw new \LogicException('Failed to create directory "'.$this->settings['cache'] .'".');
                }
            }
            return array('cache' => 'file', 'cache_dir' => $this->settings['cache']);
        }
        return null;
    }


    /**
     * Checks if the template exists
     *
     * @param $view
     * @return bool
     */
    public function exists($view)
    {
        $this->getTemplateFile($view);
        return true;
    }

    /**
     * Checks if the given template can be handled by this engine
     *
     * @param $view
     * @return bool
     */
    public function supports($view)
    {
        $this->getTemplateFile($view);
        return true;
    }


}