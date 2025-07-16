<?php

namespace CatzPrint\Printing;

class ReceiptPrinter
{
    protected array $commands = [];

    public function setTextSize(int $width, int $height): self
    {
        if ($width < 1 || $height < 1) {
            throw new \InvalidArgumentException("Text size must be positive integers");
        }
        $this->commands[] = ['action' => 'setTextSize', 'width' => $width, 'height' => $height];
        return $this;
    }

    public function centerAlign(): self
    {
        $this->commands[] = ['action' => 'align', 'value' => 'center'];
        return $this;
    }

    public function leftAlign(): self
    {
        $this->commands[] = ['action' => 'align', 'value' => 'left'];
        return $this;
    }

    public function rightAlign(): self
    {
        $this->commands[] = ['action' => 'align', 'value' => 'right'];
        return $this;
    }

    public function text(string $content): self
    {
        if (empty(trim($content))) {
            throw new \InvalidArgumentException("Text content cannot be empty");
        }
        $this->commands[] = ['action' => 'text', 'content' => $content];
        return $this;
    }

    public function twoColumnText(string $left, string $right): self
    {
        $this->commands[] = ['action' => 'twoColumnText', 'left' => $left, 'right' => $right];
        return $this;
    }

    public function line(): self
    {
        $this->commands[] = ['action' => 'line'];
        return $this;
    }

    public function feed(int $lines = 1): self
    {
        if ($lines < 1) {
            throw new \InvalidArgumentException("Feed lines must be at least 1");
        }
        $this->commands[] = ['action' => 'feed', 'lines' => $lines];
        return $this;
    }

    public function cut(): self
    {
        $this->commands[] = ['action' => 'cut'];
        return $this;
    }

    public function getJson(): string
    {
        if (empty($this->commands)) {
            throw new \RuntimeException("No commands to encode");
        }
        return json_encode($this->commands, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);
    }

    public function __toString(): string
    {
        return $this->getJson();
    }

    public function getCommands(): array
    {
        return $this->commands;
    }
}
