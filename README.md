# survos/code-bundle

Generate PHP code for Symfony applications. Like maker-bundle, but with nette/php-generator

Prerequistes for the demo

* php 8.4+
* Symfony CLI
* meilisearch


docker-compose.yaml
```yaml
```


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


https://platform.openai.com/api-keys

