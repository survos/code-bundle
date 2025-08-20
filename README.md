@todo

The bundle requires 2 classes, but you have to explicitly request them because of being in the --dev environment.  

```bash
composer require --dev survos/code-bundle nikic/php-parser nette/php-generator
```


```bash
symfony new --webapp playground && cd playground
composer req survos/code-bundle --dev
```

## General Idea

When making 'controller' (lower-case), we're referring to a method in a Controller class.  

Now let's create a simple command.
```bash
bin/console survos:make:command app:shout "greet someone, optionally in all caps"

```
