<?php

/*
 * Copyright 2011 Johannes M. Schmitt <schmittjoh@gmail.com>
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 * http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */

namespace JMS\TranslationBundle\Tests\Translation\Extractor;

use JMS\TranslationBundle\Translation\FileSourceFactory;
use Psr\Log\NullLogger;
use Doctrine\Common\Annotations\DocParser;
use JMS\TranslationBundle\Translation\Extractor\File\FormExtractor;
use Doctrine\Common\Annotations\AnnotationReader;
use Symfony\Component\Validator\Mapping\Loader\AnnotationLoader;
use Symfony\Component\Validator\Mapping\ClassMetadataFactory;
use JMS\TranslationBundle\Translation\Extractor\File\ValidationExtractor;
use JMS\TranslationBundle\Model\FileSource;
use JMS\TranslationBundle\Model\Message;
use JMS\TranslationBundle\Model\MessageCatalogue;
use JMS\TranslationBundle\Translation\Extractor\File\TranslationContainerExtractor;
use JMS\TranslationBundle\Translation\Extractor\File\DefaultPhpFileExtractor;
use Symfony\Component\Translation\MessageSelector;
use Symfony\Component\Translation\IdentityTranslator;
use Symfony\Bridge\Twig\Extension\TranslationExtension as SymfonyTranslationExtension;
use JMS\TranslationBundle\Translation\Extractor\FileExtractor;

class FileExtractorTest extends \PHPUnit_Framework_TestCase
{
    protected $twigExtractorClass;
    protected $translationExtensionClass;

    public function setUp()
    {
        $this->twigExtractorClass = 'JMS\TranslationBundle\Translation\Extractor\File\TwigFileExtractor';
        $this->translationExtensionClass = 'JMS\TranslationBundle\Twig\TranslationExtension';

        if (defined('Twig_Environment::MAJOR_VERSION') && \Twig_Environment::MAJOR_VERSION > 1) {
            $this->twigExtractorClass = 'JMS\TranslationBundle\Translation\Extractor\File\Twig2FileExtractor';
            $this->translationExtensionClass = 'JMS\TranslationBundle\Twig2\TranslationExtension';
        }
    }

    public function testExtractWithSimpleTestFixtures()
    {
        $expected = array();
        $basePath = __DIR__.'/Fixture/SimpleTest/';
        $fileSourceFactory = new FileSourceFactory('faux');

        // Controller
        $message = new Message('controller.foo');
        $message->addSource($fileSourceFactory->create(new \SplFileInfo($basePath.'Controller/DefaultController.php'), 27));
        $message->setDesc('Foo');
        $expected['controller.foo'] = $message;

        // Form Model
        $expected['form.foo'] = new Message('form.foo');
        $expected['form.bar'] = new Message('form.bar');

        // Templates
        foreach (array('php', 'twig') as $engine) {
            $message = new Message($engine.'.foo');
            $message->addSource($fileSourceFactory->create(new \SplFileInfo($basePath.'Resources/views/'.$engine.'_template.html.'.$engine), 1));
            $expected[$engine.'.foo'] = $message;

            $message = new Message($engine.'.bar');
            $message->setDesc('Bar');
            $message->addSource($fileSourceFactory->create(new \SplFileInfo($basePath.'Resources/views/'.$engine.'_template.html.'.$engine), 3));
            $expected[$engine.'.bar'] = $message;

            $message = new Message($engine.'.baz');
            $message->setMeaning('Baz');
            $message->addSource($fileSourceFactory->create(new \SplFileInfo($basePath.'Resources/views/'.$engine.'_template.html.'.$engine), 5));
            $expected[$engine.'.baz'] = $message;

            $message = new Message($engine.'.foo_bar');
            $message->setDesc('Foo');
            $message->setMeaning('Bar');
            $message->addSource($fileSourceFactory->create(new \SplFileInfo($basePath.'Resources/views/'.$engine.'_template.html.'.$engine), 7));
            $expected[$engine.'.foo_bar'] = $message;
        }

        // File with global namespace.
        $message = new Message('globalnamespace.foo');
        $message->addSource($fileSourceFactory->create(new \SplFileInfo($basePath.'GlobalNamespace.php'), 27));
        $message->setDesc('Bar');
        $expected['globalnamespace.foo'] = $message;

        $actual = $this->extract(__DIR__.'/Fixture/SimpleTest')->getDomain('messages')->all();

        asort($expected);
        asort($actual);

        $this->assertEquals($expected, $actual);
    }

    private function extract($directory)
    {
        $twig = new \Twig_Environment(new \Twig_Loader_Array());
        $twig->addExtension(new SymfonyTranslationExtension($translator = new IdentityTranslator(new MessageSelector())));
        $twig->addExtension(new $this->translationExtensionClass($translator));
        $loader=new \Twig_Loader_Filesystem(realpath(__DIR__."/Fixture/SimpleTest/Resources/views/"));
        $twig->setLoader($loader);

        $docParser = new DocParser();
        $docParser->setImports(array(
                        'desc' => 'JMS\TranslationBundle\Annotation\Desc',
                        'meaning' => 'JMS\TranslationBundle\Annotation\Meaning',
                        'ignore' => 'JMS\TranslationBundle\Annotation\Ignore',
        ));
        $docParser->setIgnoreNotImportedAnnotations(true);

        //use correct factory class depending on whether using Symfony 2 or 3
        if (class_exists('Symfony\Component\Validator\Mapping\Factory\LazyLoadingMetadataFactory')) {
            $metadataFactoryClass = 'Symfony\Component\Validator\Mapping\Factory\LazyLoadingMetadataFactory';
        } else {
            $metadataFactoryClass = 'Symfony\Component\Validator\Mapping\ClassMetadataFactory';
        }

        $factory = new $metadataFactoryClass(new AnnotationLoader(new AnnotationReader()));

        $dummyFileSourceFactory = new FileSourceFactory('faux');

        $extractor = new FileExtractor($twig, new NullLogger(), array(
            new DefaultPhpFileExtractor($docParser, $dummyFileSourceFactory),
            new TranslationContainerExtractor(),
            new $this->twigExtractorClass($twig, $dummyFileSourceFactory),
            new ValidationExtractor($factory),
            new FormExtractor($docParser, $dummyFileSourceFactory),
        ));
        $extractor->setDirectory($directory);

        return $extractor->extract();
    }
}
