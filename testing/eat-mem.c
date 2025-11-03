#include <stdio.h>
#include <stdlib.h>
#include <string.h>

int main(int argc, char *argv[]) {
    if (argc != 2) {
        fprintf(stderr, "Usage: %s <megabytes>\n", argv[0]);
        return 1;
    }

    int megabytes = atoi(argv[1]);
    long long bytes = (long long)megabytes * 1024 * 1024;

    printf("Allocating %d megabytes (%lld bytes)...\n", megabytes, bytes);

    char *mem = malloc(bytes);
    if (mem == NULL) {
        fprintf(stderr, "malloc failed\n");
        return 1;
    }

    memset(mem, 0, bytes);

    printf("Allocation successful.\n");

    return 0;
}

