@todo

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
