<?php

namespace App\Core;

use App\Config\Database;
use Exception;
use PDO;
use ReflectionClass;


class Container {
    private array $instances = [];

    /** @var array<string, string> ربط interface → implementation */
    private array $bindings = [];

    public function get(string $class) {
        if ($class === 'PDO' || $class === \PDO::class) {
            return Database::getInstance();
        }

        if (isset($this->instances[$class])) {
            return $this->instances[$class];
        }

        // إذا كان هناك binding (مثلاً interface → class)، استخدم الـ concrete class
        $resolveClass = $this->bindings[$class] ?? $class;

        $instance = $this->resolve($resolveClass);
        $this->instances[$class] = $instance;
        return $instance;
    }

    /**
     * ربط interface أو abstract class بـ implementation محددة.
     *
     * مثال:
     *   $container->bind(RepositoryInterface::class, ProductRepository::class);
     *
     * بعدها عند طلب RepositoryInterface من أي controller، يتم حقن ProductRepository.
     */
    public function bind(string $abstract, string $concrete): void
    {
        $this->bindings[$abstract] = $concrete;
    }

    /**
     * تسجيل instance جاهز (singleton).
     *
     * مثال:
     *   $container->singleton(SomeService::class, new SomeService());
     */
    public function singleton(string $class, object $instance): void
    {
        $this->instances[$class] = $instance;
    }

    private function resolve(string $class) {
        if (!class_exists($class)) {
            throw new Exception("Class {$class} does not exist.");
        }

        $reflection = new ReflectionClass($class);

        if (!$reflection->isInstantiable()) {
            throw new Exception("Class {$class} is not instantiable.");
        }

        $constructor = $reflection->getConstructor();
        if (!$constructor) {
            return new $class();
        }

        $parameters = $constructor->getParameters();
        $dependencies = [];

        foreach ($parameters as $param) {
            $type = $param->getType();
            if (!$type || $type->isBuiltin()) {
                if ($param->isDefaultValueAvailable()) {
                    $dependencies[] = $param->getDefaultValue();
                } else {
                    throw new Exception("Cannot resolve parameter {$param->getName()} in {$class}");
                }
            } else {
                $dependencies[] = $this->get($type->getName());
            }
        }

        return $reflection->newInstanceArgs($dependencies);
    }
}
