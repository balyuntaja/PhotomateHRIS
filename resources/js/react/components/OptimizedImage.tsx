import { useMemo, useState } from "react";

type OptimizedImageProps = React.ImgHTMLAttributes<HTMLImageElement> & {
  width: number;
  height: number;
  critical?: boolean;
  withSkeleton?: boolean;
};

const OptimizedImage: React.FC<OptimizedImageProps> = ({
  critical = false,
  withSkeleton = false,
  className = "",
  onLoad,
  ...props
}) => {
  const [isLoaded, setIsLoaded] = useState(false);
  const shouldLazyLoad = !critical;

  const mergedClassName = useMemo(() => {
    if (!withSkeleton) return className;
    return `${className} transition-opacity duration-300 ${
      isLoaded ? "opacity-100" : "opacity-0"
    }`;
  }, [className, isLoaded, withSkeleton]);

  return (
    <div className={withSkeleton ? "relative" : undefined}>
      {withSkeleton && !isLoaded && (
        <div className="absolute inset-0 animate-pulse bg-gray-200" />
      )}
      <img
        {...props}
        className={mergedClassName}
        loading={shouldLazyLoad ? "lazy" : "eager"}
        fetchPriority={critical ? "high" : "auto"}
        decoding="async"
        onLoad={(event) => {
          setIsLoaded(true);
          onLoad?.(event);
        }}
      />
    </div>
  );
};

export default OptimizedImage;
