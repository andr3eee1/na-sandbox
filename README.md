# NerdArena Sandbox

NerdArena Sandbox is a tool designed to sandbox a program, allowing for the constraint and measurement of its time and memory usage. It leverages cgroups for security and is primarily intended for evaluating user programs on [nerdarena.ro](https://nerdarena.ro/).

## Features

- **Time Limit:** Constrain the user time and wall time of a program.
- **Memory Limit:** Constrain the memory usage of a program.
- **I/O Redirection:** Redirect stdin, stdout, and stderr of a program.
- **Cgroups:** Uses cgroups to provide a secure sandbox environment.
- **Resource Pinning:** Pin a program to specific CPU cores and memory nodes.

## Usage

```
na-sandbox [OPTIONS...] <program> -- [PROGRAM_ARGS...]
```

### Options

- `-h`, `--help`: Prints the help message.
- `-t`, `--time <TIME>`: Sets the user time limit (e.g., `1.5s`, `500ms`).
- `-w`, `--wall-time <WALL_TIME>`: Sets the wall time limit (e.g., `2s`, `1000ms`).
- `-m`, `--memory <MEMORY>`: Sets the memory limit (e.g., `256M`, `1G`).
- `-i`, `--stdin <FILE>`: Redirects stdin from a file.
- `-o`, `--stdout <FILE>`: Redirects stdout to a file.
- `-e`, `--stderr <FILE>`: Redirects stderr to a file.
- `--root <PATH>`: Specifies the root directory for the sandbox.
- `--cpus <CPU_SET>`: A comma-separated list of CPU cores to which the program will be bound.
- `--mems <MEM_SET>`: A comma-separated list of memory nodes from which the program can allocate memory.
- `--cleanup`: If specified, the sandbox directory will be automatically removed after the program finishes execution.

### Examples

1.  **Run a simple command:**

    ```
    na-sandbox /bin/ls -- -l
    ```

2.  **Run a program with a time and memory limit:**

    ```
    na-sandbox --time 1s --memory 128M /path/to/my/program -- args
    ```

3.  **Run a program with input and output redirection:**

    ```
    na-sandbox --stdin input.txt --stdout output.txt /path/to/my/program -- args
    ```

4.  **Run a program in a specific CPU core and memory node:**

    ```
    na-sandbox --cpus 0 --mems 0 /path/to/my/program -- args
    ```

## Building

This project is written in PHP and does not require any special build process. Just clone the repository and you are ready to go.

## License

This project is licensed under the MIT License. See the [LICENSE](LICENSE) file for details.
