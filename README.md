@todo

```bash
symfony new --webapp --version=next playground && cd playground
composer req survos/code-bundle --dev
```

Now let's create a simple command.
```bash
bin/console survos:make:command app:shout "great someone, optionally in all caps"

```
